<?php

declare(strict_types=1);

namespace App\Knowledge\Vector;

use App\Knowledge\SourceKind;

/**
 * Índice vectorial en memoria para pruebas e integración continua. Se comporta como el real: un índice por empresa que hay
 * que crear antes de usar, y ninguna operación que cruce empresas. La aplicación se niega a arrancar con él en producción.
 */
final class FakeIndex implements VectorIndex
{
    /** @var array<int, array<int, array{vector: list<float>, kind: SourceKind, source: int}>> */
    private array $tenants = [];

    public bool $down = false;

    public function ensure(int $tenantId, int $dimensions): void
    {
        $this->guard();
        $this->tenants[$tenantId] ??= [];
    }

    public function upsert(int $tenantId, array $points): void
    {
        $this->guard();

        foreach ($points as $point) {
            $this->tenants[$tenantId][$point->chunkId] = ['vector' => $point->vector, 'kind' => $point->kind, 'source' => $point->sourceId];
        }
    }

    public function deleteSource(int $tenantId, SourceKind $kind, int $sourceId): void
    {
        $this->guard();

        foreach ($this->tenants[$tenantId] ?? [] as $chunkId => $point) {
            if ($point['kind'] === $kind && $point['source'] === $sourceId) {
                unset($this->tenants[$tenantId][$chunkId]);
            }
        }
    }

    public function search(int $tenantId, array $vector, int $limit): array
    {
        $this->guard();
        $scores = [];

        foreach ($this->tenants[$tenantId] ?? [] as $chunkId => $point) {
            $scores[$chunkId] = array_sum(array_map(fn (float $a, float $b): float => $a * $b, $vector, $point['vector']));
        }

        arsort($scores);

        return array_map(fn (int $chunkId, float $score): SearchHit => new SearchHit($chunkId, $score), array_slice(array_keys($scores), 0, $limit), array_slice($scores, 0, $limit));
    }

    public function chunkIds(int $tenantId): array
    {
        $this->guard();

        return array_map(intval(...), array_keys($this->tenants[$tenantId] ?? []));
    }

    public function deleteChunks(int $tenantId, array $chunkIds): void
    {
        $this->guard();

        foreach ($chunkIds as $chunkId) {
            unset($this->tenants[$tenantId][$chunkId]);
        }
    }

    public function dropTenant(int $tenantId): void
    {
        $this->guard();
        unset($this->tenants[$tenantId]);
    }

    /** Solo para pruebas: ¿existe el índice de esta empresa? */
    public function exists(int $tenantId): bool
    {
        return array_key_exists($tenantId, $this->tenants);
    }

    /** Solo para pruebas: ¿cuántos fragmentos tiene el índice de esta empresa? */
    public function count(int $tenantId): int
    {
        return count($this->tenants[$tenantId] ?? []);
    }

    /** Solo para pruebas: mete un vector suelto, como si hubiera quedado de algo que ya se borró. */
    public function plant(int $tenantId, int $chunkId, SourceKind $kind, int $sourceId, string $text): void
    {
        $this->tenants[$tenantId][$chunkId] = ['vector' => (new FakeEmbedder)->embed([$text], EmbedKind::Passage)[0], 'kind' => $kind, 'source' => $sourceId];
    }

    private function guard(): void
    {
        if ($this->down) {
            throw new IndexUnavailable('La base vectorial no responde.');
        }
    }
}
