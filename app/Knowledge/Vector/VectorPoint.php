<?php

declare(strict_types=1);

namespace App\Knowledge\Vector;

use App\Knowledge\SourceKind;

/** Un fragmento listo para indexar: su identificador (el de `kb_chunks`), su vector y de qué fuente es. */
final readonly class VectorPoint
{
    /**
     * @param  list<float>  $vector
     */
    public function __construct(
        public int $chunkId,
        public array $vector,
        public SourceKind $kind,
        public int $sourceId,
    ) {}
}
