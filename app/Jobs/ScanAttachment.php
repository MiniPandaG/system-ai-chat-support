<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Attachments\AttachmentStatus;
use App\Attachments\Scanner;
use App\Attachments\ScannerUnavailable;
use App\Enums\AuditEvent;
use App\Models\TicketAttachment;
use App\Services\AuditLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Revisa un adjunto con el antivirus. Es idempotente (solo actúa sobre uno «por revisar») y trabaja con la empresa
 * que tenía el contexto al encolarlo. Si el antivirus no responde se reintenta con espera creciente; agotados los
 * intentos el adjunto queda «no revisado», que se trata igual que uno sin revisar: nunca se entrega.
 */
class ScanAttachment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    public function __construct(public readonly string $attachmentId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120, 300];
    }

    /**
     * @throws ScannerUnavailable
     */
    public function handle(Scanner $scanner, AuditLogger $audit): void
    {
        $attachment = TicketAttachment::query()->find($this->attachmentId);

        // Pudo borrarse o revisarse ya (por ejemplo, por un reintento duplicado).
        if ($attachment === null || $attachment->status !== AttachmentStatus::Pending || $attachment->path === null) {
            return;
        }

        $stream = Storage::disk($attachment->disk)->readStream($attachment->path);

        if ($stream === null) {
            // El archivo no está en el disco: no hay nada que entregar ni que revisar.
            $attachment->forceFill(['status' => AttachmentStatus::Failed, 'scanned_at' => now()])->save();

            return;
        }

        try {
            $result = $scanner->scan($stream);
        } finally {
            fclose($stream);
        }

        if ($result->clean) {
            $attachment->forceFill(['status' => AttachmentStatus::Clean, 'scanned_at' => now()])->save();

            return;
        }

        // Con una amenaza el archivo se borra del disco: no hay motivo para conservarlo.
        Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->forceFill([
            'status' => AttachmentStatus::Infected,
            'scan_signature' => $result->signature,
            'scanned_at' => now(),
            'path' => null,
        ])->save();

        $audit->record(AuditEvent::TicketAttachmentInfected, $attachment->uploaded_by, [
            'ticket_id' => $attachment->ticket_id,
            'attachment_id' => $attachment->id,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $attachment = TicketAttachment::query()->find($this->attachmentId);

        if ($attachment !== null && $attachment->status === AttachmentStatus::Pending) {
            $attachment->forceFill(['status' => AttachmentStatus::Failed])->save();
        }
    }
}
