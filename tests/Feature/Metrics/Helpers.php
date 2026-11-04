<?php

declare(strict_types=1);

require_once __DIR__.'/../Csat/Helpers.php';

use App\Ai\AiReason;
use App\Ai\AiRunStatus;
use App\Models\AiRun;
use App\Models\CsatResponse;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Tenancy\TenantContext;
use App\Tickets\MessageAuthor;
use App\Tickets\TicketStatus;
use Illuminate\Support\Carbon;

/** Un instante en UTC, como texto de la forma «2026-11-04 15:00». */
function metricAt(string $utc): Carbon
{
    return Carbon::parse($utc, 'UTC');
}

/**
 * Un ticket con las fechas que se pidan; sin cliente con correo para no disparar encuestas.
 *
 * @param  array<string, mixed>  $dates  `created`, `first_response`, `resolved` en UTC.
 */
function metricTicket(Tenant $tenant, array $dates = [], TicketStatus $status = TicketStatus::Open): Ticket
{
    $customer = app(TenantContext::class)->run($tenant, fn () => Customer::factory()->create(['email' => null]));
    $ticket = makeTicket($tenant, [], $customer);

    app(TenantContext::class)->run($tenant, fn () => Ticket::query()->whereKey($ticket->id)->update([
        'created_at' => metricAt($dates['created'] ?? '2026-11-04 14:00:00'),
        'first_response_at' => isset($dates['first_response']) ? metricAt($dates['first_response']) : null,
        'resolved_at' => isset($dates['resolved']) ? metricAt($dates['resolved']) : null,
        'status' => isset($dates['resolved']) ? TicketStatus::Resolved->value : $status->value,
    ]));

    return $ticket->refresh();
}

/** Un mensaje del ticket de este autor, con o sin persona que lo aprobó. */
function metricMessage(Tenant $tenant, Ticket $ticket, MessageAuthor $author, ?int $userId = null): TicketMessage
{
    return app(TenantContext::class)->run($tenant, function () use ($ticket, $author, $userId): TicketMessage {
        $message = new TicketMessage(['body' => 'Mensaje de prueba']);
        $message->forceFill(['ticket_id' => $ticket->id, 'author' => $author, 'user_id' => $userId])->save();

        return $message;
    });
}

/** Un mensaje de la IA y la ejecución que lo originó (enviado solo, o el aviso de paso a una persona). */
function metricAiMessage(Tenant $tenant, Ticket $ticket, AiRunStatus $status, ?int $approvedBy = null): TicketMessage
{
    $message = metricMessage($tenant, $ticket, MessageAuthor::Ai, $approvedBy);
    $trigger = metricMessage($tenant, $ticket, MessageAuthor::Customer);

    app(TenantContext::class)->run($tenant, function () use ($ticket, $message, $trigger, $status): void {
        $run = new AiRun;
        $run->forceFill(['ticket_id' => $ticket->id, 'trigger_message_id' => $trigger->id, 'status' => $status, 'reason' => AiReason::Answered, 'message_id' => $message->id])->save();
    });

    return $message;
}

/** La respuesta de una encuesta ya contestada. */
function metricCsat(Tenant $tenant, Ticket $ticket, int $score, ?string $comment = null, string $answeredAt = '2026-11-04 14:00:00', ?string $sentAt = null): CsatResponse
{
    return app(TenantContext::class)->run($tenant, function () use ($ticket, $score, $comment, $answeredAt, $sentAt): CsatResponse {
        $row = new CsatResponse;
        $row->forceFill([
            'ticket_id' => $ticket->id,
            'token_hash' => hash('sha256', uniqid('', true)),
            'sent_at' => metricAt($sentAt ?? $answeredAt),
            'expires_at' => metricAt('2027-01-01 00:00:00'),
            'answered_at' => metricAt($answeredAt),
            'channel' => 'email',
            'score' => $score,
            'comment' => $comment,
        ])->save();

        return $row;
    });
}
