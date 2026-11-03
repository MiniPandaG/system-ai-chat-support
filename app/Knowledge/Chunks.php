<?php

declare(strict_types=1);

namespace App\Knowledge;

use App\Models\KbArticle;
use App\Models\KbChunk;
use App\Models\KbDocument;
use App\Plans\PlanLimits;
use App\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * Único lugar que escribe fragmentos. Los fragmentos son lo que la búsqueda de la IA podrá citar, así que solo existen
 * para lo publicado: se rehacen al publicar o cambiar lo publicado y se borran al despublicar o eliminar.
 */
class Chunks
{
    public function __construct(
        private readonly PlanLimits $limits,
        private readonly TenantContext $context,
    ) {}

    /**
     * @throws ValidationException
     */
    public function replaceForArticle(KbArticle $article): int
    {
        return $this->replace('article_id', $article->id, $article->body, 'body');
    }

    /**
     * @throws ValidationException
     */
    public function replaceForDocument(KbDocument $document): int
    {
        return $this->replace('document_id', $document->id, (string) $document->body, 'body');
    }

    public function purgeForArticle(KbArticle $article): void
    {
        KbChunk::query()->where('article_id', $article->id)->delete();
    }

    public function purgeForDocument(KbDocument $document): void
    {
        KbChunk::query()->where('document_id', $document->id)->delete();
    }

    /**
     * @param  'article_id'|'document_id'  $column
     *
     * @throws ValidationException
     */
    private function replace(string $column, int $sourceId, string $text, string $field): int
    {
        $tenant = $this->context->current();
        abort_if($tenant === null, 404);

        return $this->limits->exclusively($tenant, function () use ($column, $sourceId, $text, $field): int {
            $pieces = Chunker::split($text, config()->integer('knowledge.chunks.size'), config()->integer('knowledge.chunks.overlap'));
            $others = KbChunk::query()->where(fn ($query) => $query->whereNull($column)->orWhere($column, '!=', $sourceId))->count();
            $max = config()->integer('knowledge.chunks.max_per_tenant');

            if ($others + count($pieces) > $max) {
                throw ValidationException::withMessages([$field => __('knowledge.errors.chunks_max', ['max' => number_format($max, 0, ',', '.')])]);
            }

            KbChunk::query()->where($column, $sourceId)->delete();

            foreach ($pieces as $position => $piece) {
                $chunk = new KbChunk;
                $chunk->forceFill([$column => $sourceId, 'position' => $position, 'body' => $piece])->save();
            }

            return count($pieces);
        });
    }
}
