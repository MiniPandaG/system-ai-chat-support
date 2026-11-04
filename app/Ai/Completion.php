<?php

declare(strict_types=1);

namespace App\Ai;

/** Lo que devuelve un proveedor: el texto (no confiable), los tokens y por qué terminó. */
final readonly class Completion
{
    public function __construct(
        public string $text,
        public int $inputTokens,
        public int $outputTokens,
        /** «stop», «length» (se cortó por el tope) o «refusal». */
        public string $finishReason,
    ) {}
}
