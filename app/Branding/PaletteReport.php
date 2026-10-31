<?php

declare(strict_types=1);

namespace App\Branding;

/**
 * Resultado de revisar una paleta: los colores efectivos (los de la empresa más los de fábrica), el texto calculado
 * sobre cada uno y los problemas encontrados. Solo una paleta sin problemas se puede guardar.
 */
final readonly class PaletteReport
{
    /**
     * @param  array<string, string>  $colors  Los diez colores efectivos.
     * @param  array<string, string>  $foregrounds  El texto calculado sobre cada color que lleva texto encima.
     * @param  list<PaletteIssue>  $issues
     */
    public function __construct(
        public array $colors,
        public array $foregrounds,
        public array $issues,
    ) {}

    public function passes(): bool
    {
        return $this->issues === [];
    }

    /**
     * @return array{ok: bool, colors: array<string, string>, foregrounds: array<string, string>, issues: list<array{key: string, kind: string, message: string, suggestion: array{hex: string, ratio: string}|null}>}
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->passes(),
            'colors' => $this->colors,
            'foregrounds' => $this->foregrounds,
            'issues' => array_map(fn (PaletteIssue $issue): array => $issue->toArray(), $this->issues),
        ];
    }
}
