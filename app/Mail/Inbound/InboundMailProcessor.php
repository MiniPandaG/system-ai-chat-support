<?php

declare(strict_types=1);

namespace App\Mail\Inbound;

use App\Attachments\AttachmentStore;
use App\Attachments\PreparedAttachment;
use App\Broadcasting\LiveUpdates;
use App\Files\FileRejected;
use App\Jobs\ScanAttachment;
use App\Mail\MailDirection;
use App\Mail\MailKind;
use App\Mail\MailStatus;
use App\Mail\Threads;
use App\Models\Customer;
use App\Models\MailMessage;
use App\Models\MailThread;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\Tenancy\TenantContext;
use App\Tickets\MessageAuthor;
use App\Tickets\TicketStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Convierte un correo entrante en un mensaje del cliente dentro de su ticket, o lo rechaza con un motivo. Solo entran
 * respuestas a un hilo que ya existe: un correo sin token, con un token falso o de una empresa que no existe no llega a
 * ningún ticket (y no se guarda nada de él, solo se cuenta por motivo). Dentro de un hilo, el remitente debe ser el cliente
 * del ticket: saber el token no basta para escribir en él. Los motivos son una lista cerrada; nada del correo viaja en ellos.
 */
class InboundMailProcessor
{
    public const ACCEPTED = 'accepted';

    /** Cabeceras que indican una respuesta automática o una lista: contestarles (o aceptarlas) arma bucles. */
    private const AUTOMATIC = ['auto-submitted', 'x-autoreply', 'x-autorespond', 'x-auto-response-suppress'];

    public function __construct(
        private readonly Threads $threads,
        private readonly TenantContext $context,
        private readonly AttachmentStore $attachments,
    ) {}

    /**
     * @return string `accepted` o el motivo del rechazo.
     */
    public function process(InboundMail $mail): string
    {
        $previous = $this->context->current();

        try {
            return $this->handle($mail);
        } finally {
            // La búsqueda del hilo fija la empresa del token: no se queda para el siguiente correo.
            $this->context->set($previous);
        }
    }

    private function handle(InboundMail $mail): string
    {
        if ($mail->size > config()->integer('mailbox.inbound.max_bytes')) {
            return $this->countOnly('too_big');
        }

        $thread = $this->threadOf($mail);

        if ($thread === null) {
            return $this->countOnly('no_thread');
        }

        $ticket = Ticket::query()->find($thread->ticket_id);
        $customer = $ticket === null ? null : Customer::query()->find($ticket->customer_id);

        if ($ticket === null || $customer === null) {
            return $this->countOnly('no_thread');
        }

        if (MailMessage::query()->where('direction', MailDirection::In->value)->where('message_id', $mail->messageId)->exists()) {
            return 'duplicate';
        }

        $reason = $this->rejectionOf($mail, $ticket, $customer);

        if ($reason !== null) {
            return $this->reject($mail, $ticket, $thread, $reason);
        }

        return $this->accept($mail, $ticket, $thread);
    }

    /** Por qué no se acepta, si no se acepta. Solo comprobaciones que no descargan el contenido. */
    private function rejectionOf(InboundMail $mail, Ticket $ticket, Customer $customer): ?string
    {
        foreach (self::AUTOMATIC as $name) {
            $value = strtolower(trim($mail->header($name)));

            if ($value !== '' && $value !== 'no') {
                return 'auto_reply';
            }
        }

        if (in_array(strtolower(trim($mail->header('precedence'))), ['bulk', 'junk', 'list'], true) || $mail->header('list-id') !== '') {
            return 'auto_reply';
        }

        $from = $mail->from === null ? null : mb_strtolower(trim($mail->from));

        if ($from === null || $from === '' || preg_match('/\A(mailer-daemon|postmaster|no-?reply)@/', $from) === 1) {
            return 'auto_reply';
        }

        if ($from === mb_strtolower(config()->string('mail.from.address'))) {
            return 'own_message';
        }

        // Saber el token del hilo no autoriza a escribir en él: el remitente debe ser quien abrió el ticket.
        if ($customer->email === null || $from !== mb_strtolower($customer->email)) {
            return 'sender_mismatch';
        }

        // Si el servidor de entrada declara que el remitente no pasó SPF, DKIM o DMARC, se rechaza. Sin esa cabecera no hay
        // nada que comprobar y no se asume lo contrario.
        if (preg_match('/\b(spf|dkim|dmarc)\s*=\s*fail\b/i', $mail->header('authentication-results')) === 1) {
            return 'auth_failed';
        }

        if ($ticket->status === TicketStatus::Closed) {
            return 'closed';
        }

        if (RateLimiter::tooManyAttempts('mail-in:'.$ticket->id, config()->integer('mailbox.inbound.per_ticket_per_hour'))) {
            return 'rate_limited';
        }

        return null;
    }

    private function accept(InboundMail $mail, Ticket $ticket, MailThread $thread): string
    {
        $body = ($mail->body)();
        $text = ReplyText::from($body->text, $body->html, config()->integer('mailbox.inbound.max_body_chars'));
        $temporary = [];
        [$prepared, $rejectedFiles] = $this->prepareAttachments($body->attachments, $temporary);

        if ($text === '' && $prepared === []) {
            File::delete($temporary);

            return $this->reject($mail, $ticket, $thread, 'empty');
        }

        /** @var list<TicketAttachment> $stored */
        $stored = [];

        try {
            DB::transaction(function () use ($mail, $ticket, $thread, $text, $prepared, $rejectedFiles, &$stored): void {
                $locked = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

                $message = new TicketMessage(['body' => $text]);
                $message->forceFill(['ticket_id' => $locked->id, 'author' => MessageAuthor::Customer, 'user_id' => null])->save();

                foreach ($prepared as $file) {
                    $stored[] = $this->attachments->store($message, $file, null);
                }

                // Quien responde por correo reabre lo resuelto o lo que esperaba su respuesta.
                if (in_array($locked->status, [TicketStatus::Resolved, TicketStatus::WaitingCustomer], true)) {
                    $locked->forceFill(['status' => TicketStatus::Open, 'resolved_at' => null]);
                }

                $locked->forceFill(['last_message_at' => $message->created_at])->save();

                $this->record($mail, $locked, $thread, MailStatus::Accepted, $rejectedFiles > 0 ? 'files_rejected' : null, $message->id);
            });
        } catch (UniqueConstraintViolationException) {
            // El mismo correo llegó dos veces a la vez: ya lo guardó el otro.
            $this->discard($stored);

            return 'duplicate';
        } catch (Throwable $failure) {
            $this->discard($stored);

            throw $failure;
        } finally {
            File::delete($temporary);
        }

        foreach ($stored as $attachment) {
            ScanAttachment::dispatch($attachment->id);
        }

        RateLimiter::hit('mail-in:'.$ticket->id, 3600);

        $fresh = Ticket::query()->findOrFail($ticket->id);
        LiveUpdates::both($fresh);
        LiveUpdates::visitor($fresh);

        return self::ACCEPTED;
    }

    private function reject(InboundMail $mail, Ticket $ticket, MailThread $thread, string $reason): string
    {
        try {
            $this->record($mail, $ticket, $thread, MailStatus::Rejected, $reason, null);
        } catch (UniqueConstraintViolationException) {
            return 'duplicate';
        }

        return $reason;
    }

    private function record(InboundMail $mail, Ticket $ticket, MailThread $thread, MailStatus $status, ?string $reason, ?int $messageId): void
    {
        $row = new MailMessage;
        $row->forceFill([
            'ticket_id' => $ticket->id,
            'mail_thread_id' => $thread->id,
            'direction' => MailDirection::In,
            'kind' => MailKind::Customer,
            'message_id' => mb_substr($mail->messageId, 0, 255),
            'ticket_message_id' => $messageId,
            'status' => $status,
            'reason' => $reason,
        ])->save();
    }

    /** El hilo al que responde el correo: el primer token de los destinatarios o de las referencias que corresponda a uno. */
    private function threadOf(InboundMail $mail): ?MailThread
    {
        foreach ([...Threads::tokensIn($mail->recipients), ...Threads::tokensIn($mail->references)] as $found) {
            $thread = $this->threads->find($found);

            if ($thread !== null) {
                return $thread;
            }
        }

        return null;
    }

    /**
     * Cada adjunto se comprueba por su cuenta: uno que no vale se descarta y el resto del correo sigue.
     *
     * @param  list<InboundAttachment>  $attachments
     * @param  list<string>  $temporary  Se llena con los archivos temporales creados, para borrarlos al terminar.
     * @return array{0: list<PreparedAttachment>, 1: int}
     */
    private function prepareAttachments(array $attachments, array &$temporary): array
    {
        $prepared = [];
        $rejected = 0;
        $max = config()->integer('attachments.max_per_message');

        foreach ($attachments as $attachment) {
            if (count($prepared) >= $max) {
                $rejected++;

                continue;
            }

            $path = tempnam(sys_get_temp_dir(), 'inmail');

            if ($path === false) {
                $rejected++;

                continue;
            }

            $temporary[] = $path;

            try {
                file_put_contents($path, $attachment->content);
                $upload = new UploadedFile($path, $attachment->name, null, null, true);
                $prepared[] = $this->attachments->prepareFromChannel($upload);
            } catch (FileRejected) {
                $rejected++;
            }
        }

        return [$prepared, $rejected];
    }

    /** @param list<TicketAttachment> $stored */
    private function discard(array $stored): void
    {
        foreach ($stored as $attachment) {
            $this->attachments->discard($attachment);
        }
    }

    /** Cuenta un rechazo que no tiene empresa (y, por tanto, nada que guardar). */
    private function countOnly(string $reason): string
    {
        $day = now()->toDateString();
        DB::table('mail_rejections')->insertOrIgnore(['reason' => $reason, 'day' => $day, 'total' => 0]);
        DB::table('mail_rejections')->where('reason', $reason)->where('day', $day)->increment('total');

        return $reason;
    }
}
