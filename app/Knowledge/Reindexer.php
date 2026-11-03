<?php

declare(strict_types=1);

namespace App\Knowledge;

use App\Knowledge\Vector\IndexUnavailable;
use App\Knowledge\Vector\VectorIndex;
use App\Models\KbArticle;
use App\Models\KbChunk;
use App\Models\KbDocument;
use App\Tenancy\TenantContext;

/**
 * Mantiene al día el índice vectorial de la empresa en contexto: vuelve a indexar lo publicado (a mano o al cambiar de
 * modelo de embeddings) y limpia lo que sobra o falta (por ejemplo, tras una caída de la base vectorial). El índice es
 * derivado: la base de datos manda, y esto lo alinea con ella.
 */
class Reindexer
{
    public function __construct(
        private readonly IndexQueue $queue,
        private readonly VectorIndex $index,
        private readonly TenantContext $context,
    ) {}

    /** Vuelve a pedir la indexación de una fuente publicada; con una no publicada no hace nada. */
    public function reindex(KbArticle|KbDocument $source): bool
    {
        if (! $this->isPublished($source)) {
            return false;
        }

        $this->queue->requestIndex($source);

        return true;
    }

    /** Vuelve a pedir la indexación de todo lo publicado de la empresa en contexto; devuelve cuántas fuentes. */
    public function reindexAll(): int
    {
        $count = 0;

        foreach (KbArticle::query()->where('status', ArticleStatus::Published->value)->get() as $article) {
            $this->queue->requestIndex($article);
            $count++;
        }

        foreach (KbDocument::query()->where('status', DocumentStatus::Published->value)->get() as $document) {
            $this->queue->requestIndex($document);
            $count++;
        }

        return $count;
    }

    /**
     * Compara el índice con la base: quita del índice lo que ya no existe en la base y vuelve a indexar las fuentes a las
     * que les falta algo.
     *
     * @return array{removed: int, requeued: int}
     *
     * @throws IndexUnavailable
     */
    public function reconcile(): array
    {
        $tenantId = $this->context->id();
        abort_if($tenantId === null, 404);

        $indexed = $this->index->chunkIds($tenantId);
        $published = $this->publishedChunks();
        $orphans = array_values(array_diff($indexed, array_keys($published)));

        if ($orphans !== []) {
            $this->index->deleteChunks($tenantId, $orphans);
        }

        $sources = [];

        foreach (array_diff(array_keys($published), $indexed) as $chunkId) {
            $sources[$published[$chunkId]] = true;
        }

        foreach (array_keys($sources) as $key) {
            [$kind, $id] = explode(':', $key);
            $source = SourceKind::from($kind)->model()::query()->find((int) $id);

            if ($source !== null) {
                $this->queue->requestIndex($source);
            }
        }

        return ['removed' => count($orphans), 'requeued' => count($sources)];
    }

    /**
     * Los fragmentos que deberían estar en el índice: los de lo publicado. La clave es el identificador del fragmento y el
     * valor, su fuente («article:3»).
     *
     * @return array<int, string>
     */
    private function publishedChunks(): array
    {
        $articles = KbArticle::query()->where('status', ArticleStatus::Published->value)->pluck('id');
        $documents = KbDocument::query()->where('status', DocumentStatus::Published->value)->pluck('id');
        $chunks = KbChunk::query()
            ->where(fn ($query) => $query->whereIn('article_id', $articles)->orWhereIn('document_id', $documents))
            ->get(['id', 'article_id', 'document_id']);
        $map = [];

        foreach ($chunks as $chunk) {
            $map[$chunk->id] = $chunk->article_id !== null ? 'article:'.$chunk->article_id : 'document:'.$chunk->document_id;
        }

        return $map;
    }

    private function isPublished(KbArticle|KbDocument $source): bool
    {
        return $source instanceof KbArticle ? $source->isPublished() : $source->status === DocumentStatus::Published;
    }
}
