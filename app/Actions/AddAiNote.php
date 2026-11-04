<?php

declare(strict_types=1);

namespace App\Actions;

use App\Broadcasting\LiveUpdates;
use App\Models\Ticket;
use App\Models\TicketNote;

/**
 * La IA deja una nota interna: el resumen con el que pasa un caso a una persona. Sin autor (`user_id` nulo) para que se
 * distinga de lo que escribe el equipo. Solo la ve el equipo que atiende, como cualquier nota.
 */
class AddAiNote
{
    public function __invoke(Ticket $ticket, string $body): TicketNote
    {
        $note = new TicketNote(['body' => $body]);
        $note->forceFill(['ticket_id' => $ticket->id, 'user_id' => null])->save();

        LiveUpdates::ticket($ticket);

        return $note;
    }
}
