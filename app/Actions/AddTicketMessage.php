<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Concerns\LocksOpenTicket;
use App\Attachments\AttachmentStore;
use App\Broadcasting\LiveUpdates;
use App\Files\FileRejected;
use App\Jobs\ScanAttachment;
use App\Mail\Outbox;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\Models\User;
use App\Tickets\MessageAuthor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Responde al cliente, con o sin archivos adjuntos. El mensaje es texto plano y es lo único que mueve la fecha del
 * último mensaje, que ordena la bandeja: una nota interna no la toca. Los adjuntos se comprueban todos antes de
 * guardar nada; si uno no vale, no se crea ni el mensaje. Cada uno nace «por revisar» y se encola su revisión.
 */
class AddTicketMessage
{
    use LocksOpenTicket;

    public function __construct(
        private readonly AttachmentStore $attachments,
        private readonly Outbox $outbox,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     * @param  MessageAuthor  $as  Quien figura como autor: `Agent` siempre salvo un borrador de la IA aprobado sin cambios.
     *
     * @throws ValidationException
     */
    public function __invoke(User $author, Ticket $ticket, string $body, array $files = [], MessageAuthor $as = MessageAuthor::Agent): TicketMessage
    {
        try {
            $prepared = $this->attachments->prepare($files, $author);
        } catch (FileRejected $rejected) {
            throw ValidationException::withMessages(['files' => $rejected->getMessage()]);
        }

        /** @var list<TicketAttachment> $stored */
        $stored = [];

        try {
            $message = DB::transaction(function () use ($author, $ticket, $body, $prepared, $as, &$stored): TicketMessage {
                $locked = $this->lockOpen($ticket);

                $message = new TicketMessage(['body' => $body]);
                $message->forceFill(['ticket_id' => $locked->id, 'author' => $as, 'user_id' => $author->id])->save();

                foreach ($prepared as $file) {
                    $stored[] = $this->attachments->store($message, $file, $author);
                }

                $locked->forceFill(['last_message_at' => $message->created_at])->save();

                return $message;
            });
        } catch (Throwable $failure) {
            // Si algo falló después de guardar archivos en el disco, no deben quedar huérfanos.
            foreach ($stored as $attachment) {
                $this->attachments->discard($attachment);
            }

            throw $failure;
        }

        // Fuera de la transacción: la revisión solo empieza cuando el mensaje y sus filas ya existen.
        foreach ($stored as $attachment) {
            ScanAttachment::dispatch($attachment->id);
        }

        LiveUpdates::both($ticket);
        LiveUpdates::visitor($ticket);
        // Si el cliente dejó su correo, también se le avisa por ahí.
        $this->outbox->reply($message);

        return $message;
    }
}
