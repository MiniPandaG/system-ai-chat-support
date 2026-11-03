<?php

declare(strict_types=1);

namespace App\Knowledge;

enum DocumentStatus: string
{
    /** Subido, esperando al antivirus. */
    case Scanning = 'scanning';

    /** Limpio, extrayendo el texto. */
    case Extracting = 'extracting';

    /** Texto extraído, esperando la revisión de una persona. */
    case Review = 'review';
    case Published = 'published';
    case Failed = 'failed';

    /** El antivirus encontró una amenaza. */
    case Blocked = 'blocked';

    /** ¿Algo lo está procesando ahora mismo? */
    public function isProcessing(): bool
    {
        return $this === self::Scanning || $this === self::Extracting;
    }
}
