<?php

declare(strict_types=1);

require_once __DIR__.'/../Knowledge/Helpers.php';
require_once __DIR__.'/../Widget/Helpers.php';

use App\Ai\AiMode;
use App\Ai\Completion;
use App\Ai\CompletionRequest;
use App\Ai\Escalator;
use App\Ai\Providers\FakeModel;
use App\Ai\Reply\ReplyGenerator;
use App\Ai\Reply\ReplyOptions;
use App\Jobs\GenerateAiReply;
use App\Models\AiRun;
use App\Models\AiSettings;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketNote;
use App\Tenancy\TenantContext;
use App\Tickets\MessageAuthor;

/** Un parecido mínimo bajo: el vector de prueba solo compara palabras repetidas. */
const LOW_FLOOR = 0.2;

function lowFloor(): ReplyOptions
{
    return new ReplyOptions(minScore: LOW_FLOOR);
}

function publishedKnowledge(object $test, Tenant $tenant, string $title = 'Política de devoluciones', string $body = 'Aceptamos devoluciones de productos hasta 30 días después de la compra. El reembolso tarda 5 días hábiles.'): void
{
    $article = makeArticle($tenant, ['title' => $title, 'body' => $body, 'category' => 'Pagos']);
    $test->actingAs(kbAdmin($tenant))->post("/knowledge/articles/{$article->id}/publish")->assertSessionHasNoErrors();
}

function conversationMessage(Tenant $tenant, Ticket $ticket, MessageAuthor $author, string $body): TicketMessage
{
    return app(TenantContext::class)->run($tenant, function () use ($ticket, $author, $body): TicketMessage {
        $message = new TicketMessage(['body' => $body]);
        $message->forceFill(['ticket_id' => $ticket->id, 'author' => $author])->save();

        return $message;
    });
}

function replyJson(bool $canAnswer = true, bool $needsHuman = false, string $answer = 'Tienes 30 días para devolverlo.', array $sources = [1]): string
{
    return json_encode(['puede_responder' => $canAnswer, 'necesita_humano' => $needsHuman, 'respuesta' => $answer, 'fuentes' => $sources], JSON_UNESCAPED_UNICODE);
}

function generateReply(Tenant $tenant, TicketMessage $trigger, ?Closure $handler = null, ?ReplyOptions $options = null, string $catalogId = 'fake-fast'): AiRun
{
    app(FakeModel::class)->handler = $handler ?? fn (CompletionRequest $r): Completion => new Completion(replyJson(), 900, 60, 'stop');

    return app(TenantContext::class)->run($tenant, function () use ($trigger, $options, $catalogId): AiRun {
        $ticket = Ticket::query()->findOrFail($trigger->ticket_id);

        return app(ReplyGenerator::class)->generate($ticket, $trigger, $catalogId, $options ?? lowFloor());
    });
}

/** Un ticket con un solo mensaje del cliente: la pregunta. */
function questionTicket(Tenant $tenant, string $question = '¿Cuántos días tengo para hacer devoluciones de productos?'): TicketMessage
{
    $ticket = makeTicket($tenant);

    return conversationMessage($tenant, $ticket, MessageAuthor::Customer, $question);
}

function sentRequest(int $index = 0): CompletionRequest
{
    return app(FakeModel::class)->requests[$index];
}

function aiSettings(Tenant $tenant, array $values = []): void
{
    app(TenantContext::class)->run($tenant, function () use ($values): void {
        $settings = AiSettings::query()->first() ?? new AiSettings;
        $settings->forceFill(['widget_mode' => AiMode::Shadow, 'email_mode' => AiMode::Shadow, 'identify_as_ai' => true, 'require_knowledge' => true, 'reply_model' => null, ...$values])->save();
    });
}

/** Ejecuta el trabajo que prepara la respuesta de la IA, como lo haría la cola. */
function runReplyFlow(Tenant $tenant, Ticket $ticket, TicketMessage $message, string $channel = 'widget'): void
{
    app(TenantContext::class)->run($tenant, fn () => app()->call([new GenerateAiReply($ticket->id, $message->id, $channel), 'handle']));
}

/**
 * Un modelo de prueba que responde con una respuesta con fuentes al pedido normal y con un resumen al pedido de resumen.
 *
 * @param  array<string, mixed>  $answer
 */
function fakeFlowModel(array $answer = [], string|Closure|null $summary = 'El cliente pregunta por devoluciones y la IA no pudo resolverlo.'): void
{
    app(FakeModel::class)->handler = function (CompletionRequest $request) use ($answer, $summary): Completion {
        if ($request->system === Escalator::SUMMARY_SYSTEM) {
            $text = $summary instanceof Closure ? $summary($request) : $summary;

            return new Completion($text ?? '', 400, 60, 'stop');
        }

        return new Completion(json_encode([...['puede_responder' => true, 'necesita_humano' => false, 'respuesta' => 'Tienes 30 días para devolverlo.', 'fuentes' => [1]], ...$answer], JSON_UNESCAPED_UNICODE), 900, 60, 'stop');
    };
}

/** @return list<string> */
function aiNotesOf(Tenant $tenant, Ticket $ticket): array
{
    return app(TenantContext::class)->run($tenant, fn (): array => TicketNote::query()->where('ticket_id', $ticket->id)->orderBy('id')->pluck('body')->all());
}

/** Lo que el modelo recibió en cada llamada, para saber qué se le pidió. @return list<CompletionRequest> */
function modelCalls(): array
{
    return app(FakeModel::class)->requests;
}

function latestRun(Tenant $tenant): AiRun
{
    return app(TenantContext::class)->run($tenant, fn () => AiRun::query()->orderByDesc('id')->firstOrFail());
}

/** Una empresa con el widget encendido y la IA en modo borrador en los dos canales, más lo que se pase. */
function aiTenant(array $settings = []): Tenant
{
    $tenant = widgetTenant();
    aiSettings($tenant, $settings);

    return $tenant;
}

/** La escala de confianza del vector de prueba: lo que se parece mucho a la pregunta da 100. */
function calibrateFakeScores(): void
{
    config(['ai.confidence.floor' => 0.1, 'ai.confidence.ceil' => 0.3]);
}

/**
 * Un ticket de la empresa con un mensaje del cliente que dispara a la IA.
 *
 * @return array{0: Ticket, 1: TicketMessage}
 */
function askedTicket(Tenant $tenant, string $question = '¿Cuántos días tengo para hacer devoluciones de productos?', array $ticketAttributes = []): array
{
    $ticket = makeTicket($tenant, $ticketAttributes);

    return [$ticket, conversationMessage($tenant, $ticket, MessageAuthor::Customer, $question)];
}

/** @return list<CompletionRequest> */
function summaryCalls(): array
{
    return array_values(array_filter(modelCalls(), fn (CompletionRequest $call): bool => $call->system === Escalator::SUMMARY_SYSTEM));
}

/** @return list<CompletionRequest> */
function replyCalls(): array
{
    return array_values(array_filter(modelCalls(), fn (CompletionRequest $call): bool => $call->system !== Escalator::SUMMARY_SYSTEM));
}

function aiMessagesOf(Tenant $tenant, Ticket $ticket): array
{
    return app(TenantContext::class)->run($tenant, fn (): array => TicketMessage::query()->where('ticket_id', $ticket->id)->orderBy('id')->get()->all());
}
