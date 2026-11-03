<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Attachments\Scanner;
use App\Attachments\ScannerUnavailable;
use App\Broadcasting\LiveUpdates;
use App\Enums\AuditEvent;
use App\Knowledge\DocumentFailure;
use App\Knowledge\DocumentStatus;
use App\Knowledge\SourceKind;
use App\Models\KbDocument;
use App\Services\AuditLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Revisa con el antivirus un documento recién subido. Es idempotente (solo actúa sobre uno «por revisar») y trabaja con
 * la empresa que tenía el contexto al encolarlo. Si el antivirus no responde se reintenta con espera creciente; agotados
 * los intentos el documento falla: nunca se lee el contenido de un archivo que no se pudo revisar.
 */
class ScanKbDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 6;

    public function __construct(public readonly int $documentId) {}

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
        $document = KbDocument::query()->find($this->documentId);

        if ($document === null || $document->status !== DocumentStatus::Scanning || $document->path === null) {
            return;
        }

        $stream = Storage::disk($document->disk)->readStream($document->path);

        if ($stream === null) {
            $this->markFailed($document, DocumentFailure::FileMissing);

            return;
        }

        try {
            $result = $scanner->scan($stream);
        } finally {
            fclose($stream);
        }

        if ($result->clean) {
            $document->forceFill(['status' => DocumentStatus::Extracting, 'scanned_at' => now()])->save();
            LiveUpdates::knowledge($document->tenant_id, SourceKind::Document, $document->id);
            ExtractKbDocument::dispatch($document->id);

            return;
        }

        // Con una amenaza el archivo se borra del disco: no hay motivo para conservarlo.
        Storage::disk($document->disk)->delete($document->path);
        $document->forceFill(['status' => DocumentStatus::Blocked, 'scanned_at' => now(), 'path' => null])->save();
        LiveUpdates::knowledge($document->tenant_id, SourceKind::Document, $document->id);

        $audit->record(AuditEvent::KnowledgeDocumentBlocked, $document->created_by, ['document_id' => $document->id]);
    }

    public function failed(Throwable $exception): void
    {
        $document = KbDocument::query()->find($this->documentId);

        if ($document !== null && $document->status === DocumentStatus::Scanning) {
            $this->markFailed($document, DocumentFailure::ScanUnavailable);
        }
    }

    private function markFailed(KbDocument $document, DocumentFailure $failure): void
    {
        if ($document->path !== null) {
            Storage::disk($document->disk)->delete($document->path);
        }

        $document->forceFill(['status' => DocumentStatus::Failed, 'failure' => $failure, 'path' => null])->save();
        LiveUpdates::knowledge($document->tenant_id, SourceKind::Document, $document->id);
    }
}
