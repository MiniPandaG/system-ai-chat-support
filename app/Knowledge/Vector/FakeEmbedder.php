<?php

declare(strict_types=1);

namespace App\Knowledge\Vector;

/**
 * Embeddings de mentira para pruebas e integración continua: una bolsa de palabras con hash, normalizada. Dos textos que
 * comparten palabras salen parecidos y dos que no, no; no entiende significado, pero basta para probar el flujo. La
 * aplicación se niega a arrancar con él en producción.
 */
final class FakeEmbedder implements Embedder
{
    public const DIMENSIONS = 64;

    public function embed(array $texts, EmbedKind $kind): array
    {
        return array_map(fn (string $text): array => $this->vector($text), $texts);
    }

    public function dimensions(): int
    {
        return self::DIMENSIONS;
    }

    /**
     * @return list<float>
     */
    private function vector(string $text): array
    {
        $vector = array_fill(0, self::DIMENSIONS, 0.0);
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $words);

        foreach ($words[0] as $word) {
            $vector[hexdec(substr(hash('sha256', $word), 0, 8)) % self::DIMENSIONS] += 1.0;
        }

        $norm = sqrt(array_sum(array_map(fn (float $value): float => $value * $value, $vector)));

        return array_values($norm === 0.0 ? $vector : array_map(fn (float $value): float => $value / $norm, $vector));
    }
}
