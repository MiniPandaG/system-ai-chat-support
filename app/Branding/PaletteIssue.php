<?php

declare(strict_types=1);

namespace App\Branding;

/**
 * Un problema de una paleta, ya explicado en español y, cuando se pudo, con el tono más cercano que sí cumple.
 */
final readonly class PaletteIssue
{
    public function __construct(
        public string $key,
        public string $kind,
        public string $message,
        public ?string $suggestionHex = null,
        public ?string $suggestionRatio = null,
    ) {}

    /**
     * @return array{key: string, kind: string, message: string, suggestion: array{hex: string, ratio: string}|null}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'kind' => $this->kind,
            'message' => $this->message,
            'suggestion' => $this->suggestionHex === null ? null : ['hex' => $this->suggestionHex, 'ratio' => (string) $this->suggestionRatio],
        ];
    }
}
