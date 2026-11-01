<?php

declare(strict_types=1);

namespace App\Attachments;

use Illuminate\Http\UploadedFile;

/**
 * Un archivo que ya pasó todas las comprobaciones y espera a guardarse. Se prepara antes de escribir nada, así un
 * archivo malo rechaza el mensaje entero sin dejar restos en el disco.
 */
final readonly class PreparedAttachment
{
    public function __construct(
        public UploadedFile $upload,
        /** Tipo que se guarda: el real para imágenes, PDF y texto; el canónico de Office para docx y xlsx. */
        public string $mime,
        /** Nombre limpio, para mostrarlo y para la descarga. */
        public string $name,
        public int $size,
        public string $sha256,
    ) {}
}
