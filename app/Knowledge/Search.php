<?php

declare(strict_types=1);

namespace App\Knowledge;

use App\Knowledge\Vector\Embedder;
use App\Knowledge\Vector\EmbeddingUnavailable;
use App\Knowledge\Vector\EmbedKind;
use App\Knowledge\Vector\IndexUnavailable;
use App\Knowledge\Vector\VectorIndex;
use App\Models\KbArticle;
use App\Models\KbChunk;
use App\Models\KbDocument;
use App\Tenancy\TenantContext;

/**
 * Búsqueda por significado en el conocimiento publicado de la empresa en contexto (RIA-04). El filtro por empresa se
 * aplica antes de que nada llegue a nadie, y dos veces: la consulta apunta al índice de una sola empresa y, además, cada
 * resultado se vuelve a leer de la base con el filtro automático de empresa y solo cuenta si su fuente sigue publicada.
 * Un vector que sobrara en el índice (de algo borrado o de otra empresa) se descarta sin mostrarse.
 */
class Search
{
    public function __construct(
        private readonly Embedder $embedder,
        private readonly VectorIndex $index,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return list<SearchResult>
     *
     * @throws EmbeddingUnavailable
     * @throws IndexUnavailable
     */
    public function search(string $query, ?int $limit = null): array
    {
        $tenantId = $this->context->id();
        abort_if($tenantId === null, 404);

        $query = mb_substr(TextCleaner::line($query), 0, config()->integer('knowledge.search.query_max'));
        $limit ??= config()->integer('knowledge.search.limit');

        if ($query === '' || $limit < 1) {
            return [];
        }

        $vector = $this->embedder->embed([$query], EmbedKind::Query)[0] ?? [];
        // Se piden de más: los que ya no existen en la base se descartan y aun así deben quedar `$limit`.
        $hits = $this->index->search($tenantId, $vector, $limit * 3);

        if ($hits === []) {
            return [];
        }

        $chunks = KbChunk::query()->whereIn('id', array_map(fn ($hit): int => $hit->chunkId, $hits))->with(['article', 'document'])->get()->keyBy('id');
        $results = [];

        foreach ($hits as $hit) {
            $chunk = $chunks->get($hit->chunkId);
            $source = $chunk === null ? null : ($chunk->article ?? $chunk->document);

            if ($chunk === null || ! $this->isPublished($source)) {
                continue;
            }

            $results[] = new SearchResult($chunk->id, $chunk->body, $hit->score, SourceKind::of($source), $source->id, $source->title, $source->category);

            if (count($results) === $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * @phpstan-assert-if-true KbArticle|KbDocument $source
     */
    private function isPublished(KbArticle|KbDocument|null $source): bool
    {
        return match (true) {
            $source instanceof KbArticle => $source->isPublished(),
            $source instanceof KbDocument => $source->status === DocumentStatus::Published,
            default => false,
        };
    }
}
