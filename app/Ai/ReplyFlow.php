<?php

declare(strict_types=1);

namespace App\Ai;

use App\Actions\SendAiMessage;
use App\Ai\Reply\Composer;
use App\Ai\Reply\ReplyGenerator;
use App\Ai\Reply\ReplyOptions;
use App\Ai\Rules\EscalationRules;
use App\Ai\Rules\Schedule;
use App\Broadcasting\LiveUpdates;
use App\Knowledge\TextCleaner;
use App\Models\AiRun;
use App\Models\AiSettings;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Tickets\MessageAuthor;
use App\Tickets\TicketStatus;
use Illuminate\Validation\ValidationException;

/**
 * Todo lo que pasa desde que el cliente escribe hasta que su mensaje tiene una respuesta, un borrador o una persona
 * encargada (HU-08, HU-09). Las reglas las aplica este código y no el modelo (RIA-05): horario, tope de gasto, créditos,
 * temas, límite de mensajes y petición de una persona se resuelven antes de llamar a ningún modelo; la confianza la calcula
 * el código y decide si la respuesta sale, queda de borrador o pasa a una persona. Debe haber una empresa en contexto.
 */
class ReplyFlow
{
    /** Por qué no respondió la IA y el caso, en vez de quedarse sin atender, pasa a una persona con un resumen. */
    private const ESCALATES = [AiReason::NoKnowledge, AiReason::ModelDeclined, AiReason::NeedsHuman, AiReason::Uncited];

    public function __construct(
        private readonly ReplyGenerator $generator,
        private readonly ModelCatalog $catalog,
        private readonly AiBudget $budget,
        private readonly Schedule $schedule,
        private readonly EscalationRules $rules,
        private readonly Escalator $escalator,
        private readonly Composer $composer,
        private readonly SendAiMessage $send,
    ) {}

    public function handle(Ticket $ticket, TicketMessage $message, AiChannel $channel): void
    {
        $settings = AiSettings::current();
        $mode = $settings->modeFor($channel);

        if ($mode === AiMode::Off || $this->superseded($ticket, $message) || AiRun::query()->where('trigger_message_id', $message->id)->exists()) {
            return;
        }

        $modelId = $settings->reply_model ?? $this->catalog->available()[0]->id ?? null;
        $skip = $this->skipReason($ticket, $settings, $modelId);

        if ($skip !== null || $modelId === null) {
            $this->newRun($ticket, $message, $channel, $mode, AiRunStatus::Skipped, $skip ?? AiReason::ModelUnavailable, $modelId);
            LiveUpdates::ticket($ticket);

            return;
        }

        $before = $this->beforeModel($ticket, $message, $settings);

        if ($before !== null) {
            $run = $this->newRun($ticket, $message, $channel, $mode, AiRunStatus::Pending, $before, $modelId);
            $this->escalator->escalate($ticket, $message, $run, $before, $settings);

            return;
        }

        $options = new ReplyOptions(requireKnowledge: $settings->require_knowledge, channel: $channel, mode: $mode, tone: $this->tone($settings));
        $run = $this->generator->generate($ticket, $message, $modelId, $options);

        $this->afterModel($ticket, $message, $run, $settings, $mode);
        LiveUpdates::ticket($ticket);
    }

    private function skipReason(Ticket $ticket, AiSettings $settings, ?string $modelId): ?AiReason
    {
        return match (true) {
            $ticket->status === TicketStatus::Closed => AiReason::TicketClosed,
            // Si una persona ya lleva el ticket, la IA no se mete.
            $ticket->assigned_to !== null => AiReason::Assigned,
            ! $this->schedule->allows($settings) => AiReason::OffSchedule,
            default => $this->budget->pausedReason($settings) ?? ($modelId !== null && $this->catalog->usable($modelId) !== null ? null : AiReason::ModelUnavailable),
        };
    }

    /** Lo que se decide sin modelo: el cliente pide una persona, toca un tema reservado o la IA ya llegó a su límite de mensajes. */
    private function beforeModel(Ticket $ticket, TicketMessage $message, AiSettings $settings): ?AiReason
    {
        return match (true) {
            $this->rules->humanRequested($message->body) => AiReason::CustomerAskedHuman,
            $this->rules->topicMatched($settings, $message->body) => AiReason::Topic,
            $this->rules->messageLimitReached($ticket, $settings) => AiReason::MessageLimit,
            default => null,
        };
    }

    private function afterModel(Ticket $ticket, TicketMessage $message, AiRun $run, AiSettings $settings, AiMode $mode): void
    {
        if ($run->status === AiRunStatus::NoAnswer && in_array($run->reason, self::ESCALATES, true)) {
            $this->escalator->escalate($ticket, $message, $run, $run->reason, $settings);

            return;
        }

        if ($run->status !== AiRunStatus::Answered) {
            return;
        }

        if ($run->reason === AiReason::NeedsHuman) {
            $this->escalator->escalate($ticket, $message, $run, AiReason::NeedsHuman, $settings);

            return;
        }

        if (($run->confidence ?? 0) < $settings->min_confidence) {
            $this->escalator->escalate($ticket, $message, $run, AiReason::LowConfidence, $settings);

            return;
        }

        $run->body = $this->composer->compose($run, $settings);

        if ($mode === AiMode::Shadow) {
            $run->status = AiRunStatus::Drafted;
            $run->save();

            return;
        }

        $this->sendAuto($ticket, $run);
    }

    /** Modo automático: la respuesta sale sola, solo porque pasó todas las reglas. */
    private function sendAuto(Ticket $ticket, AiRun $run): void
    {
        try {
            $sent = ($this->send)($ticket, $run->body ?? '');
        } catch (ValidationException) {
            $run->status = AiRunStatus::Skipped;
            $run->reason = AiReason::TicketClosed;
            $run->save();

            return;
        }

        $run->status = AiRunStatus::Sent;
        $run->message_id = $sent->id;
        $run->save();
    }

    private function newRun(Ticket $ticket, TicketMessage $message, AiChannel $channel, AiMode $mode, AiRunStatus $status, AiReason $reason, ?string $modelId): AiRun
    {
        $run = new AiRun;
        $run->forceFill([
            'ticket_id' => $ticket->id,
            'trigger_message_id' => $message->id,
            'channel' => $channel->value,
            'mode' => $mode->value,
            'status' => $status,
            'reason' => $reason,
            'catalog_id' => $modelId,
        ])->save();

        return $run;
    }

    /** ¿El cliente escribió algo más nuevo? Entonces esa respuesta cubrirá todo y esta sobra. */
    private function superseded(Ticket $ticket, TicketMessage $message): bool
    {
        return TicketMessage::query()->where('ticket_id', $ticket->id)->where('author', MessageAuthor::Customer->value)->where('id', '>', $message->id)->exists();
    }

    /** El tono de la empresa, ya redactado: las frases fijas son del código y el personalizado se limpia como cualquier texto de la empresa. */
    private function tone(AiSettings $settings): ?string
    {
        return match ($settings->tone) {
            ToneStyle::Formal => (string) __('ai.tone.formal'),
            ToneStyle::Friendly => (string) __('ai.tone.friendly'),
            ToneStyle::Custom => $settings->tone_custom === null ? null : trim(TextCleaner::clean($settings->tone_custom)),
        };
    }
}
