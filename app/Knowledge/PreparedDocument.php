<?php

declare(strict_types=1);

namespace App\Knowledge;

use Illuminate\Http\UploadedFile;

/** Un documento que ya pasó las comprobaciones de contenido y espera guardarse. */
final readonly class PreparedDocument
{
    public function __construct(
        public UploadedFile $upload,
        public string $name,
        public string $type,
        public int $size,
        public string $sha256,
    ) {}
}
