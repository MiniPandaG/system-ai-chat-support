<?php

declare(strict_types=1);

namespace App\Attachments;

/**
 * Lo que dijo el antivirus de un archivo: limpio, o con el nombre de la amenaza que encontró.
 */
final readonly class ScanResult
{
    private function __construct(public bool $clean, public ?string $signature) {}

    public static function clean(): self
    {
        return new self(true, null);
    }

    public static function infected(string $signature): self
    {
        return new self(false, $signature);
    }
}
