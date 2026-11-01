<?php

declare(strict_types=1);

namespace App\Actions;

use App\Broadcasting\LiveUpdates;
use App\Enums\AuditEvent;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Plans\Limit;
use App\Plans\LimitReached;
use App\Plans\PlanLimits;
use App\Services\AuditLogger;
use App\Tickets\Channel;
use App\Tickets\MessageAuthor;
use App\Tickets\TicketPriority;
use App\Tickets\TicketStatus;

/**
 * Abre un ticket desde el panel con su primer mensaje. Todo ocurre con la fila de la empresa bloqueada: así el límite
 * del plan se comprueba sin que otra petición se cuele y el número de ticket sale sin huecos ni repetidos.
 */
class CreateTicket
{
    public function __construct(
        private readonly PlanLimits $limits,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{subject: string, description: string, priority: TicketPriority, customer_id: int|null, customer_name: string|null, customer_email: string|null, assigned_to: int|null}  $data  Ya validado: el cliente y quien lo atiende pertenecen a la empresa.
     *
     * @throws LimitReached
     */
    public function __invoke(User $creator, Tenant $tenant, array $data): Ticket
    {
        return $this->limits->exclusively($tenant, function (Tenant $locked) use ($creator, $data): Ticket {
            $this->limits->ensure($locked, Limit::TicketsPerMonth);

            $customer = $data['customer_id'] === null
                ? Customer::query()->create(['name' => (string) $data['customer_name'], 'email' => $data['customer_email']])
                : Customer::query()->findOrFail($data['customer_id']);

            $locked->forceFill(['last_ticket_number' => $locked->last_ticket_number + 1])->save();

            $ticket = new Ticket(['subject' => $data['subject']]);
            $ticket->forceFill([
                'number' => $locked->last_ticket_number,
                'customer_id' => $customer->id,
                'status' => TicketStatus::Open,
                'priority' => $data['priority'],
                'channel' => Channel::Panel,
                'assigned_to' => $data['assigned_to'],
                'created_by' => $creator->id,
                'last_message_at' => now(),
            ])->save();

            $message = new TicketMessage(['body' => $data['description']]);
            $message->forceFill(['ticket_id' => $ticket->id, 'author' => MessageAuthor::Customer, 'user_id' => $creator->id])->save();

            $this->audit->record(AuditEvent::TicketCreated, $creator->id, ['ticket_id' => $ticket->id, 'number' => $ticket->number]);

            if ($ticket->assigned_to !== null) {
                $this->audit->record(AuditEvent::TicketAssigned, $creator->id, ['ticket_id' => $ticket->id, 'to' => $ticket->assigned_to]);
            }

            LiveUpdates::inbox($ticket);

            return $ticket;
        });
    }
}
