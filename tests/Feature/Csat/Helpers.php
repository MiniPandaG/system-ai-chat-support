<?php

declare(strict_types=1);

require_once __DIR__.'/../Mail/Helpers.php';

use App\Actions\UpdateTicket;
use App\Jobs\SendSurveyEmail;
use App\Models\CsatResponse;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Tenancy\TenantContext;
use App\Tickets\TicketStatus;
use Illuminate\Support\Facades\Queue;

/** Cambia el estado de un ticket como lo haría el equipo, dentro de su empresa. */
function csatSetStatus(Tenant $tenant, Ticket $ticket, TicketStatus $status): void
{
    app(TenantContext::class)->run($tenant, fn () => app(UpdateTicket::class)(null, Ticket::query()->findOrFail($ticket->id), ['status' => $status]));
}

function csatRow(Tenant $tenant, Ticket $ticket): ?CsatResponse
{
    return app(TenantContext::class)->run($tenant, fn () => CsatResponse::query()->where('ticket_id', $ticket->id)->first());
}

/**
 * Resuelve el ticket con la cola falsa y devuelve el token del enlace que habría viajado en el correo (nulo si no hubo correo).
 */
function csatResolve(Tenant $tenant, Ticket $ticket, TicketStatus $status = TicketStatus::Resolved): ?string
{
    Queue::fake();
    csatSetStatus($tenant, $ticket, $status);

    return Queue::pushed(SendSurveyEmail::class)->first()?->token;
}

/** Un ticket con cliente que dejó su correo, ya resuelto, y el token de su encuesta. @return array{0: Ticket, 1: string} */
function csatResolvedTicket(Tenant $tenant, string $email = 'marisol@cliente-ejemplo.com', string $name = 'Marisol Quintero'): array
{
    $ticket = mailTicket($tenant, $email, $name);
    $token = csatResolve($tenant, $ticket);
    expect($token)->toBeString();

    return [$ticket, (string) $token];
}
