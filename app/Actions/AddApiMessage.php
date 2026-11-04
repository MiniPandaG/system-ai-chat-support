<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Concerns\LocksOpenTicket;
use App\Broadcasting\LiveUpdates;
use App\Mail\Outbox;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Tickets\FirstResponse;
use App\Tickets\MessageAuthor;
use App\Tickets\TicketStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Añade un mensaje que escribe un sistema de la empresa por la API, a nombre del cliente o del equipo (sin persona
 * concreta: el mensaje no lleva usuario). Un mensaje del cliente reabre un ticket resuelto o en espera del cliente; uno
 * del equipo avisa por correo si el cliente dejó su dirección. Un ticket cerrado no recibe nada.
 */
class AddApiMessage
{
    use LocksOpenTicket;

    public function __construct(private readonly Outbox $outbox) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Ticket $ticket, MessageAuthor $author, string $body): TicketMessage
    {
        $message = DB::transaction(function () use ($ticket, $author, $body): TicketMessage {
            $locked = $this->lockOpen($ticket);

            $message = new TicketMessage(['body' => $body]);
            $message->forceFill(['ticket_id' => $locked->id, 'author' => $author, 'user_id' => null])->save();

            if ($author === MessageAuthor::Customer && in_array($locked->status, [TicketStatus::Resolved, TicketStatus::WaitingCustomer], true)) {
                $locked->forceFill(['status' => TicketStatus::Open, 'resolved_at' => null]);
            }

            FirstResponse::stamp($locked, $message);
            $locked->forceFill(['last_message_at' => $message->created_at])->save();

            return $message;
        });

        $fresh = Ticket::query()->findOrFail($ticket->id);
        LiveUpdates::both($fresh);
        LiveUpdates::visitor($fresh);

        if ($author === MessageAuthor::Agent) {
            $this->outbox->reply($message);
        }

        return $message;
    }
}
