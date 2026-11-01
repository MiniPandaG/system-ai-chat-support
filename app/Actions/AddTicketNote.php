<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Concerns\LocksOpenTicket;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deja una nota que solo ve el equipo. No cambia la fecha del último mensaje: la bandeja se ordena por lo que el
 * cliente ve, no por lo que el equipo se dice a sí mismo.
 */
class AddTicketNote
{
    use LocksOpenTicket;

    /**
     * @throws ValidationException
     */
    public function __invoke(User $author, Ticket $ticket, string $body): TicketNote
    {
        return DB::transaction(function () use ($author, $ticket, $body): TicketNote {
            $locked = $this->lockOpen($ticket);

            $note = new TicketNote(['body' => $body]);
            $note->forceFill(['ticket_id' => $locked->id, 'user_id' => $author->id])->save();

            return $note;
        });
    }
}
