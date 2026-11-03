<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\EmailTheme;
use App\Mail\MailDirection;
use App\Mail\MailKind;
use App\Mail\MailStatus;
use App\Mail\Threads;
use App\Mail\TicketEmail;
use App\Models\Customer;
use App\Models\MailMessage;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envía un correo ya registrado en `mail_messages`. Es idempotente (solo actúa sobre uno «en cola»), trabaja con la
 * empresa que tenía el contexto al encolarlo y se reintenta con espera creciente; agotados los intentos queda «fallido»
 * sin perder el mensaje del ticket, que ya está en la conversación.
 */
class SendTicketEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly int $mailMessageId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(Threads $threads): void
    {
        $row = MailMessage::query()->find($this->mailMessageId);

        if ($row === null || $row->status !== MailStatus::Queued || $row->direction !== MailDirection::Out) {
            return;
        }

        $ticket = Ticket::query()->find($row->ticket_id);
        $customer = $ticket === null ? null : Customer::query()->find($ticket->customer_id);
        $source = $row->ticket_message_id === null ? null : TicketMessage::query()->find($row->ticket_message_id);
        $tenant = Tenant::query()->find($row->tenant_id);
        $thread = $ticket === null ? null : $threads->forTicket($ticket);

        // Si el cliente ya no tiene correo (o el ticket desapareció) no hay a dónde enviar: no se reintenta.
        if ($ticket === null || $customer === null || $customer->email === null || $customer->email === '' || $source === null || $tenant === null || $thread === null) {
            $this->finish($row, MailStatus::Failed, 'no_recipient');

            return;
        }

        $row->forceFill(['attempts' => $row->attempts + 1])->save();

        Mail::send(new TicketEmail(
            $row->kind,
            EmailTheme::for($tenant),
            $customer->email,
            $customer->name,
            $ticket->number,
            $ticket->subject,
            $row->kind === MailKind::Reply ? $source->body : '',
            $threads->replyAddress($thread),
            $row->message_id,
            $this->lastIncomingId($row),
        ));

        $this->finish($row, MailStatus::Sent, null);
    }

    public function failed(Throwable $exception): void
    {
        $row = MailMessage::query()->find($this->mailMessageId);

        if ($row !== null && $row->status === MailStatus::Queued) {
            // Solo el motivo, nunca el texto de la excepción (puede traer direcciones o credenciales).
            $this->finish($row, MailStatus::Failed, 'send_failed');
        }
    }

    /** El identificador del último correo que el cliente nos escribió en este hilo: la respuesta lo cita. */
    private function lastIncomingId(MailMessage $row): ?string
    {
        $incoming = MailMessage::query()
            ->where('mail_thread_id', $row->mail_thread_id)
            ->where('direction', MailDirection::In->value)
            ->where('status', MailStatus::Accepted->value)
            ->orderByDesc('id')
            ->first();

        return $incoming?->message_id;
    }

    private function finish(MailMessage $row, MailStatus $status, ?string $reason): void
    {
        $row->forceFill(['status' => $status, 'reason' => $reason])->save();
    }
}
