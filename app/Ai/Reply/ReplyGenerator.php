<?php

declare(strict_types=1);

namespace App\Ai\Reply;

use App\Ai\AiGateway;
use App\Ai\AiPurpose;
use App\Ai\AiReason;
use App\Ai\AiRunStatus;
use App\Ai\InputTooLong;
use App\Ai\ModelUnavailable;
use App\Ai\Providers\ProviderUnavailable;
use App\Ai\Rules\Confidence;
use App\Knowledge\CompanyContext;
use App\Knowledge\Search;
use App\Knowledge\SearchResult;
use App\Knowledge\Vector\EmbeddingUnavailable;
use App\Knowledge\Vector\IndexUnavailable;
use App\Models\AiRun;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Tickets\MessageAuthor;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Prepara la respuesta de la IA a un mensaje del cliente (RIA-01, RIA-04): busca conocimiento de la empresa, arma lo
 * que se le manda al modelo, valida lo que devuelve y deja constancia en una ejecución. No envía nada al cliente: qué se hace
 * con la respuesta lo decide quien la llama. Una misma ejecución nunca se repite para el mismo mensaje.
 */
class ReplyGenerator
{
    public function __construct(
        private readonly Search $search,
        private readonly CompanyContext $context,
        private readonly PromptBuilder $prompts,
        private readonly ReplyParser $parser,
        private readonly AiGateway $gateway,
        private readonly Confidence $confidence,
    ) {}

    public function generate(Ticket $ticket, TicketMessage $trigger, string $catalogId, ReplyOptions $options = new ReplyOptions): AiRun
    {
        abort_unless($trigger->ticket_id === $ticket->id && $trigger->author === MessageAuthor::Customer, 404);

        $existing = AiRun::query()->where('trigger_message_id', $trigger->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $run = new AiRun;
        $run->forceFill(['ticket_id' => $ticket->id, 'trigger_message_id' => $trigger->id, 'channel' => $options->channel?->value, 'mode' => $options->mode?->value, 'status' => AiRunStatus::Pending, 'reason' => AiReason::Answered, 'catalog_id' => $catalogId]);

        try {
            $run->save();
        } catch (UniqueConstraintViolationException) {
            return AiRun::query()->where('trigger_message_id', $trigger->id)->firstOrFail();
        }

        return $this->run($run, $ticket, $trigger, $catalogId, $options);
    }

    private function run(AiRun $run, Ticket $ticket, TicketMessage $trigger, string $catalogId, ReplyOptions $options): AiRun
    {
        try {
            $chunks = $this->knowledge($ticket, $trigger, $options);
        } catch (EmbeddingUnavailable|IndexUnavailable) {
            return $this->finish($run, AiRunStatus::Failed, AiReason::ProviderError);
        }

        $run->top_score = $chunks === [] ? null : round(max(array_map(fn (SearchResult $chunk): float => $chunk->score, $chunks)), 3);

        if ($chunks === [] && $options->requireKnowledge) {
            return $this->finish($run, AiRunStatus::NoAnswer, AiReason::NoKnowledge);
        }

        $companyText = $this->context->text();
        $messages = $this->prompts->messages($ticket, $trigger, $companyText, $chunks, $options->tone);

        try {
            $result = $this->gateway->complete(AiPurpose::Reply, $catalogId, PromptBuilder::SYSTEM, $messages, json: true, runId: $run->id);
        } catch (ModelUnavailable) {
            return $this->finish($run, AiRunStatus::Failed, AiReason::ModelUnavailable);
        } catch (InputTooLong) {
            return $this->finish($run, AiRunStatus::Failed, AiReason::InputTooLong);
        } catch (ProviderUnavailable) {
            return $this->finish($run, AiRunStatus::Failed, AiReason::ProviderError);
        }

        if ($result->finishReason === 'length') {
            return $this->finish($run, AiRunStatus::InvalidOutput, AiReason::Truncated);
        }

        if ($result->finishReason === 'refusal') {
            return $this->finish($run, AiRunStatus::NoAnswer, AiReason::ModelDeclined);
        }

        $allowedText = $companyText."\n".implode("\n", array_map(fn (SearchResult $chunk): string => $chunk->text, $chunks));

        try {
            $reply = $this->parser->parse($result->text, $allowedText, count($chunks));
        } catch (InvalidReply) {
            return $this->finish($run, AiRunStatus::InvalidOutput, AiReason::InvalidOutput);
        }

        if (! $reply->canAnswer) {
            return $this->finish($run, AiRunStatus::NoAnswer, $reply->needsHuman ? AiReason::NeedsHuman : AiReason::ModelDeclined);
        }

        // Una respuesta que no se apoya en ningún fragmento no se puede comprobar, y es la forma típica de una invención.
        if ($chunks !== [] && $reply->sources === []) {
            return $this->finish($run, AiRunStatus::NoAnswer, AiReason::Uncited);
        }

        $run->body = $reply->answer;
        $run->sources = array_map(function (int $number) use ($chunks): array {
            $chunk = $chunks[$number - 1];

            return ['kind' => $chunk->kind->value, 'id' => $chunk->sourceId, 'title' => $chunk->sourceTitle, 'chunk_id' => $chunk->chunkId, 'score' => round($chunk->score, 3)];
        }, $reply->sources);
        $run->confidence = $this->confidence->score(array_map(fn (array $source): float => $source['score'], $run->sources), $reply->needsHuman);

        return $this->finish($run, AiRunStatus::Answered, $reply->needsHuman ? AiReason::NeedsHuman : AiReason::Answered);
    }

    /**
     * El conocimiento parecido a lo que preguntó el cliente: su último mensaje y, si fue corto, también el anterior suyo,
     * para que «¿y cuánto cuesta?» se busque con su contexto. Solo cuenta lo que pasa del parecido mínimo.
     *
     * @return list<SearchResult>
     */
    private function knowledge(Ticket $ticket, TicketMessage $trigger, ReplyOptions $options): array
    {
        $query = $trigger->body;

        if (mb_strlen($query) < 40) {
            $previous = $ticket->messages()->where('author', MessageAuthor::Customer->value)->where('id', '<', $trigger->id)->orderByDesc('id')->value('body');
            $query = is_string($previous) ? $previous."\n".$query : $query;
        }

        $floor = $options->floor();

        return array_values(array_filter(
            $this->search->search($query, $options->limit()),
            fn (SearchResult $chunk): bool => $chunk->score >= $floor,
        ));
    }

    private function finish(AiRun $run, AiRunStatus $status, AiReason $reason): AiRun
    {
        $run->status = $status;
        $run->reason = $reason;
        $run->save();

        return $run;
    }
}
