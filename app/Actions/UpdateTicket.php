<?php

declare(strict_types=1);

namespace App\Actions;

use App\Broadcasting\LiveUpdates;
use App\Csat\Surveys;
use App\Enums\AuditEvent;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AuditLogger;
use App\Tickets\TicketPriority;
use App\Tickets\TicketStatus;

/**
 * Cambia el estado, la prioridad o quien atiende un ticket. Solo toca lo que viene en los cambios; las fechas de
 * resuelto y cerrado siguen al estado y se limpian al reabrir.
 */
class UpdateTicket
{
    public function __construct(private readonly AuditLogger $audit, private readonly Surveys $surveys) {}

    /**
     * @param  array{status?: TicketStatus, priority?: TicketPriority, assigned_to?: int|null}  $changes  Ya validado: quien atiende pertenece a la empresa y es administrador o agente activo.
     */
    public function __invoke(?User $actor, Ticket $ticket, array $changes): Ticket
    {
        $previousAssignee = $ticket->assigned_to;

        if (isset($changes['status'])) {
            $this->applyStatus($ticket, $changes['status']);
        }

        if (isset($changes['priority'])) {
            $ticket->forceFill(['priority' => $changes['priority']]);
        }

        if (array_key_exists('assigned_to', $changes)) {
            $ticket->forceFill(['assigned_to' => $changes['assigned_to']]);
        }

        $ticket->save();

        if ($ticket->wasChanged()) {
            LiveUpdates::both($ticket);
        }

        if ($ticket->wasChanged('status')) {
            LiveUpdates::visitor($ticket);
        }

        // La primera vez que se resuelve o se cierra, el cliente recibe la encuesta de satisfacción.
        if ($ticket->wasChanged('status') && in_array($ticket->status, [TicketStatus::Resolved, TicketStatus::Closed], true)) {
            $this->surveys->offer($ticket);
        }

        if ($ticket->assigned_to !== $previousAssignee) {
            $this->audit->record(AuditEvent::TicketAssigned, $actor?->id, [
                'ticket_id' => $ticket->id,
                'from' => $previousAssignee,
                'to' => $ticket->assigned_to,
            ]);
        }

        return $ticket;
    }

    private function applyStatus(Ticket $ticket, TicketStatus $status): void
    {
        $ticket->forceFill([
            'status' => $status,
            'resolved_at' => match ($status) {
                TicketStatus::Resolved => $ticket->resolved_at ?? now(),
                TicketStatus::Closed => $ticket->resolved_at ?? now(),
                default => null,
            },
            'closed_at' => $status === TicketStatus::Closed ? ($ticket->closed_at ?? now()) : null,
        ]);
    }
}
