<?php

declare(strict_types=1);

namespace App\Knowledge;

use App\Knowledge\Vector\Embedder;
use App\Knowledge\Vector\EmbeddingUnavailable;
use App\Knowledge\Vector\EmbedKind;
use App\Knowledge\Vector\IndexUnavailable;
use App\Knowledge\Vector\VectorIndex;
use App\Knowledge\Vector\VectorPoint;
use App\Models\KbArticle;
use App\Models\KbChunk;
use App\Models\KbDocument;
use App\Tenancy\TenantContext;

/**
 * Lleva los fragmentos publicados al índice vectorial de la empresa en contexto. Es el único que escribe en el índice:
 * trabaja siempre con el identificador de la empresa del contexto, así que no hay forma de indexar en el de otra. Lo que
 * no está publicado no entra, y lo que ya estaba de esa fuente se quita antes de poner lo nuevo.
 */
class Indexer
{
    public function __construct(
        private readonly Embedder $embedder,
        private readonly VectorIndex $index,
        private readonly TenantContext $context,
    ) {}

    /**
     * @throws EmbeddingUnavailable
     * @throws IndexUnavailable
     */
    public function index(SourceKind $kind, int $sourceId): void
    {
        $tenantId = $this->tenantId();
        $source = $kind->model()::query()->find($sourceId);

        if (! ($source instanceof KbArticle || $source instanceof KbDocument) || ! $this->isPublished($source)) {
            $this->index->deleteSource($tenantId, $kind, $sourceId);

            return;
        }

        $title = $source->title;
        $chunks = KbChunk::query()->where($kind->column(), $sourceId)->orderBy('position')->get();

        $this->index->ensure($tenantId, $this->embedder->dimensions());
        $this->index->deleteSource($tenantId, $kind, $sourceId);

        foreach ($chunks->chunk(max(1, config()->integer('knowledge.embeddings.batch'))) as $group) {
            $batch = array_values($group->all());
            $vectors = $this->embedder->embed(array_map(fn (KbChunk $chunk): string => $title."\n".$chunk->body, $batch), EmbedKind::Passage);

            $this->index->upsert($tenantId, array_map(fn (KbChunk $chunk, int $position): VectorPoint => new VectorPoint($chunk->id, $vectors[$position] ?? [], $kind, $sourceId), $batch, array_keys($batch)));
        }
    }

    /**
     * @throws IndexUnavailable
     */
    public function purge(SourceKind $kind, int $sourceId): void
    {
        $this->index->deleteSource($this->tenantId(), $kind, $sourceId);
    }

    private function isPublished(KbArticle|KbDocument $source): bool
    {
        return $source instanceof KbArticle ? $source->isPublished() : $source->status === DocumentStatus::Published;
    }

    private function tenantId(): int
    {
        $id = $this->context->id();
        abort_if($id === null, 404);

        return $id;
    }
}
