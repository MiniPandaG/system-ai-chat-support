<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Broadcasting\LiveUpdates;
use App\Knowledge\DocumentFailure;
use App\Knowledge\DocumentStatus;
use App\Knowledge\ExtractionFailed;
use App\Knowledge\SourceKind;
use App\Knowledge\TextExtractor;
use App\Models\KbDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Saca el texto de un documento que el antivirus ya declaró limpio y lo deja «en revisión» para que una persona lo lea
 * antes de publicarlo. Un solo intento: lo que falla por el contenido (un PDF dañado, sin texto, demasiado largo) fallará
 * igual al repetirlo. Termine como termine, el archivo original se borra del disco: solo queda el texto.
 */
class ExtractKbDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** Segundos máximos; un PDF que tarda más que esto se descarta. */
    public int $timeout = 90;

    public function __construct(public readonly int $documentId) {}

    public function handle(TextExtractor $extractor): void
    {
        $document = KbDocument::query()->find($this->documentId);

        if ($document === null || $document->status !== DocumentStatus::Extracting || $document->path === null) {
            return;
        }

        $content = Storage::disk($document->disk)->get($document->path);

        if (! is_string($content)) {
            $this->markFailed($document, DocumentFailure::FileMissing);

            return;
        }

        try {
            $result = $extractor->extract($document->type, $content);
        } catch (ExtractionFailed $failed) {
            $this->markFailed($document, $failed->failure);

            return;
        }

        Storage::disk($document->disk)->delete($document->path);
        $document->forceFill([
            'status' => DocumentStatus::Review,
            'body' => $result['text'],
            'pages' => $result['pages'],
            'failure' => null,
            'path' => null,
        ])->save();
        LiveUpdates::knowledge($document->tenant_id, SourceKind::Document, $document->id);
    }

    public function failed(Throwable $exception): void
    {
        $document = KbDocument::query()->find($this->documentId);

        if ($document !== null && $document->status === DocumentStatus::Extracting) {
            $this->markFailed($document, DocumentFailure::ExtractionFailed);
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
