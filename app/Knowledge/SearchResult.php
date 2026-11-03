<?php

declare(strict_types=1);

namespace App\Knowledge;

/** Un resultado de la búsqueda: el fragmento, de dónde sale y qué tan parecido es (de 0 a 1). */
final readonly class SearchResult
{
    public function __construct(
        public int $chunkId,
        public string $text,
        public float $score,
        public SourceKind $kind,
        public int $sourceId,
        public string $sourceTitle,
        public ?string $category,
    ) {}
}
