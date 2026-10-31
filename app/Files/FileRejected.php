<?php

declare(strict_types=1);

namespace App\Files;

use RuntimeException;

/**
 * Un archivo que no cumple las reglas. El mensaje ya está en español y dice qué pasó, para mostrarlo tal cual.
 */
class FileRejected extends RuntimeException
{
    /**
     * @param  array<string, scalar>  $replace
     */
    public static function because(string $reason, array $replace = []): self
    {
        return new self((string) __('files.rejected.'.$reason, $replace));
    }
}
