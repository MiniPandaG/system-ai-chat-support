<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Concerns\LocksOpenTicket;
use App\Broadcasting\LiveUpdates;
use App\Mail\Outbox;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Tickets\MessageAuthor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * La IA envía un mensaje al cliente por su cuenta (modo automático o aviso de que pasa a una persona). Sin persona de por
 * medio, el autor es la IA y no hay usuario. Se escribe en un ticket abierto, como cualquier respuesta: uno cerrado la
 * rechaza. Por correo, si el cliente dejó su dirección, sale igual que una respuesta del equipo.
 */
class SendAiMessage
{
    use LocksOpenTicket;

    public function __construct(private readonly Outbox $outbox) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Ticket $ticket, string $body): TicketMessage
    {
        $message = DB::transaction(function () use ($ticket, $body): TicketMessage {
            $locked = $this->lockOpen($ticket);

            $message = new TicketMessage(['body' => $body]);
            $message->forceFill(['ticket_id' => $locked->id, 'author' => MessageAuthor::Ai, 'user_id' => null])->save();

            $locked->forceFill(['last_message_at' => $message->created_at])->save();

            return $message;
        });

        LiveUpdates::both($ticket);
        LiveUpdates::visitor($ticket);
        $this->outbox->reply($message);

        return $message;
    }
}
