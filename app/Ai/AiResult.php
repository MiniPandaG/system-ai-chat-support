<?php

declare(strict_types=1);

namespace App\Ai;

/** El resultado de una llamada hecha por la pasarela: la respuesta y lo que costó. */
final readonly class AiResult
{
    public function __construct(
        public string $text,
        public int $inputTokens,
        public int $outputTokens,
        public int $costMicro,
        public string $catalogId,
        public string $finishReason,
        public int $callId,
    ) {}
}
