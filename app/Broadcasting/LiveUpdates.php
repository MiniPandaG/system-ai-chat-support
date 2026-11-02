<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Events\InboxChanged;
use App\Events\TicketChanged;
use App\Events\TicketTyping;
use App\Events\WidgetChanged;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WidgetSession;
use App\Tickets\Channel;

/**
 * Único lugar desde donde se avisa a los navegadores de un cambio. Los avisos salen después de confirmar la
 * transacción (si se deshace, no sale ninguno) y solo llevan identificadores.
 */
final class LiveUpdates
{
    /** Cambió la conversación o los datos del ticket: avisa a quien lo tiene abierto. */
    public static function ticket(Ticket $ticket): void
    {
        TicketChanged::dispatch($ticket->tenant_id, $ticket->id);
    }

    /** Lo mismo para quien solo tiene los identificadores (por ejemplo, un trabajo en cola que revisó un adjunto). */
    public static function ticketById(int $tenantId, int $ticketId): void
    {
        TicketChanged::dispatch($tenantId, $ticketId);
    }

    /** Una persona del equipo está escribiendo en el ticket (señal pasajera: no se guarda ni se reintenta). */
    public static function typing(Ticket $ticket, User $user): void
    {
        TicketTyping::dispatch($ticket->tenant_id, $ticket->id, $user->id);
    }

    /** Cambió algo que la bandeja muestra o cuenta: avisa a quien la tiene abierta. */
    public static function inbox(Ticket $ticket): void
    {
        InboxChanged::dispatch($ticket->tenant_id, $ticket->id);
    }

    /**
     * Si el ticket nació en el widget, avisa al navegador del visitante de que su conversación cambió (una respuesta, un
     * cambio de estado). Las notas internas y las etiquetas no pasan por aquí: no son del cliente.
     */
    public static function visitor(Ticket $ticket): void
    {
        if ($ticket->channel !== Channel::Widget) {
            return;
        }

        foreach (WidgetSession::query()->where('ticket_id', $ticket->id)->get(['id']) as $session) {
            WidgetChanged::dispatch($ticket->tenant_id, $session->id);
        }
    }

    /** Cambió el ticket y también lo que la bandeja muestra de él. */
    public static function both(Ticket $ticket): void
    {
        self::ticket($ticket);
        self::inbox($ticket);
    }
}
