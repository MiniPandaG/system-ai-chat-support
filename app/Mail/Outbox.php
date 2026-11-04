<?php

declare(strict_types=1);

namespace App\Mail;

use App\Jobs\SendTicketEmail;
use App\Models\Customer;
use App\Models\MailMessage;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Tickets\MessageAuthor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Decide qué correos salen hacia un cliente y los deja en cola. Solo hay correo si el cliente dejó su dirección. Cada
 * mensaje del ticket genera como mucho un correo de cada tipo (lo garantiza la base de datos), así que repetir una
 * llamada nunca envía dos veces. Debe haber una empresa en contexto.
 */
class Outbox
{
    public function __construct(private readonly Threads $threads) {}

    /** El equipo (o la IA, con una persona que la aprobó) respondió: avisa al cliente por correo. Las notas internas nunca llegan aquí. */
    public function reply(TicketMessage $message): void
    {
        if ($message->author === MessageAuthor::Customer || trim($message->body) === '') {
            return;
        }

        $ticket = Ticket::query()->find($message->ticket_id);
        $customer = $ticket === null ? null : $this->recipient($ticket);

        if ($ticket === null || $customer === null) {
            return;
        }

        $this->queue($ticket, $message, MailKind::Reply);
    }

    /**
     * Confirma al cliente que su mensaje llegó (el primer correo automático de un ticket). A una misma dirección se le
     * envían pocas por día: nadie puede usar el chat para llenar la bandeja de otra persona.
     */
    public function received(Ticket $ticket): void
    {
        $customer = $this->recipient($ticket);
        $first = TicketMessage::query()->where('ticket_id', $ticket->id)->orderBy('id')->first();

        if ($customer === null || $first === null || $customer->email === null) {
            return;
        }

        $key = 'mail-received:'.Threads::hash(mb_strtolower($customer->email));

        if (RateLimiter::tooManyAttempts($key, config()->integer('mailbox.outbound.received_per_address_per_day'))) {
            return;
        }

        if ($this->queue($ticket, $first, MailKind::Received)) {
            RateLimiter::hit($key, 86400);
        }
    }

    private function recipient(Ticket $ticket): ?Customer
    {
        $customer = Customer::query()->find($ticket->customer_id);

        return $customer !== null && $customer->email !== null && $customer->email !== '' ? $customer : null;
    }

    private function queue(Ticket $ticket, TicketMessage $message, MailKind $kind): bool
    {
        $thread = $this->threads->forTicket($ticket);
        $row = new MailMessage;
        $row->forceFill([
            'ticket_id' => $ticket->id,
            'mail_thread_id' => $thread->id,
            'direction' => MailDirection::Out,
            'kind' => $kind,
            'message_id' => $this->threads->messageId($thread),
            'ticket_message_id' => $message->id,
            'status' => MailStatus::Queued,
        ]);

        try {
            $row->save();
        } catch (UniqueConstraintViolationException) {
            // Ya hay un correo de este tipo para este mensaje.
            return false;
        }

        SendTicketEmail::dispatch($row->id)->afterCommit();

        return true;
    }
}
