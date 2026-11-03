<?php

declare(strict_types=1);

namespace App\Knowledge;

use App\Enums\AuditEvent;
use App\Files\FileRejected;
use App\Jobs\ScanKbDocument;
use App\Models\KbDocument;
use App\Models\Tenant;
use App\Models\User;
use App\Plans\PlanLimits;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sube, revisa, publica y borra documentos. Un documento recorre un camino fijo: antivirus, lectura del texto, revisión
 * de una persona y solo entonces publicado (nunca se publica solo). Es el único camino de escritura: cuida los topes con
 * la fila de la empresa bloqueada y deja la auditoría solo con identificadores.
 */
class Documents
{
    public function __construct(
        private readonly PlanLimits $limits,
        private readonly AuditLogger $audit,
        private readonly DocumentStore $store,
        private readonly Chunks $chunks,
        private readonly IndexQueue $indexing,
    ) {}

    /**
     * @throws FileRejected
     * @throws ValidationException
     */
    public function upload(User $actor, Tenant $tenant, UploadedFile $file): KbDocument
    {
        $prepared = $this->store->prepare($file, $actor);

        return $this->limits->exclusively($tenant, function () use ($actor, $prepared): KbDocument {
            $max = config()->integer('knowledge.documents.max');

            if (KbDocument::query()->count() >= $max) {
                throw ValidationException::withMessages(['file' => __('knowledge.errors.documents_max', ['max' => $max])]);
            }

            $document = $this->store->store($prepared, $actor);
            $this->audit->record(AuditEvent::KnowledgeDocumentUploaded, $actor->id, ['document_id' => $document->id]);
            ScanKbDocument::dispatch($document->id)->afterCommit();

            return $document;
        });
    }

    /**
     * Título, categoría y texto se editan solo mientras el documento está en revisión: lo publicado no cambia sin
     * despublicarlo antes.
     *
     * @throws ValidationException
     */
    public function update(User $actor, KbDocument $document, string $title, string $body, ?string $category): KbDocument
    {
        if ($document->status !== DocumentStatus::Review) {
            throw ValidationException::withMessages(['body' => __('knowledge.errors.document_locked')]);
        }

        $document->forceFill([
            'title' => TextCleaner::line($title),
            'body' => TextCleaner::clean($body),
            'category' => $this->category($category),
            'updated_by' => $actor->id,
        ])->save();

        return $document;
    }

    /**
     * @throws ValidationException
     */
    public function publish(User $actor, KbDocument $document): KbDocument
    {
        if ($document->status === DocumentStatus::Published) {
            return $document;
        }

        if ($document->status !== DocumentStatus::Review) {
            throw ValidationException::withMessages(['body' => __('knowledge.errors.document_not_ready')]);
        }

        if (trim((string) $document->body) === '') {
            throw ValidationException::withMessages(['body' => __('knowledge.errors.publish_empty')]);
        }

        DB::transaction(function () use ($actor, $document): void {
            $document->forceFill(['status' => DocumentStatus::Published, 'published_at' => now(), 'updated_by' => $actor->id])->save();
            $this->chunks->replaceForDocument($document);
            $this->indexing->requestIndex($document);
            $this->audit->record(AuditEvent::KnowledgeDocumentPublished, $actor->id, ['document_id' => $document->id]);
        });

        return $document;
    }

    public function unpublish(User $actor, KbDocument $document): KbDocument
    {
        if ($document->status === DocumentStatus::Published) {
            DB::transaction(function () use ($actor, $document): void {
                $document->forceFill(['status' => DocumentStatus::Review, 'published_at' => null, 'updated_by' => $actor->id])->save();
                $this->chunks->purgeForDocument($document);
                $this->indexing->requestPurge($document);
                $this->audit->record(AuditEvent::KnowledgeDocumentUnpublished, $actor->id, ['document_id' => $document->id]);
            });
        }

        return $document;
    }

    public function delete(User $actor, KbDocument $document): void
    {
        DB::transaction(function () use ($actor, $document): void {
            $id = $document->id;
            $this->chunks->purgeForDocument($document);
            $this->indexing->requestPurgeById(SourceKind::Document, $id);
            $document->delete();
            $this->audit->record(AuditEvent::KnowledgeDocumentDeleted, $actor->id, ['document_id' => $id]);
        });

        $this->store->discard($document);
    }

    private function category(?string $category): ?string
    {
        $clean = $category === null ? '' : TextCleaner::line($category);

        return $clean === '' ? null : $clean;
    }
}
