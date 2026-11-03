<?php

declare(strict_types=1);

namespace App\Knowledge\Vector;

/** Un resultado crudo del índice: el fragmento y qué tan parecido es (de 0 a 1). */
final readonly class SearchHit
{
    public function __construct(public int $chunkId, public float $score) {}
}
