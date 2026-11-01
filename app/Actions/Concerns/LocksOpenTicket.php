<?php

declare(strict_types=1);

namespace App\Actions\Concerns;

use App\Models\Ticket;
use App\Tickets\TicketStatus;
use Illuminate\Validation\ValidationException;

/**
 * Lee el ticket otra vez con la fila bloqueada y rechaza escribir en uno cerrado. La comprobación va aquí y no en la
 * petición porque el ticket pudo cerrarse entre que se abrió la pantalla y se envió el texto.
 */
trait LocksOpenTicket
{
    /**
     * @throws ValidationException
     */
    private function lockOpen(Ticket $ticket): Ticket
    {
        $locked = Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->status === TicketStatus::Closed) {
            throw ValidationException::withMessages(['body' => __('tickets.errors.ticket_closed')]);
        }

        return $locked;
    }
}
