<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Broadcasting\LiveUpdates;
use App\Knowledge\Indexer;
use App\Knowledge\IndexStatus;
use App\Knowledge\SourceKind;
use App\Knowledge\Vector\EmbeddingUnavailable;
use App\Knowledge\Vector\IndexUnavailable;
use App\Models\KbArticle;
use App\Models\KbDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Indexa lo publicado de una fuente. Es idempotente (rehace todo lo de esa fuente con lo que hay en la base en ese
 * momento) y trabaja con la empresa que tenía el contexto al encolarlo. Si el servicio no responde se reintenta con
 * espera creciente; agotados los intentos la fuente queda con «error de indexación» y se puede reindexar a mano.
 */
class IndexKbSource implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    public function __construct(public readonly string $kind, public readonly int $sourceId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    /**
     * @throws EmbeddingUnavailable
     * @throws IndexUnavailable
     */
    public function handle(Indexer $indexer): void
    {
        $kind = SourceKind::from($this->kind);
        $indexer->index($kind, $this->sourceId);
        $this->finish($kind, IndexStatus::Indexed);
    }

    public function failed(Throwable $exception): void
    {
        $this->finish(SourceKind::from($this->kind), IndexStatus::Failed);
    }

    /** Anota cómo terminó, solo si la fuente sigue publicada, y avisa a quien tiene la pantalla abierta. */
    private function finish(SourceKind $kind, IndexStatus $status): void
    {
        $source = $kind->model()::query()->find($this->sourceId);

        if ($source === null || $source->index_status === null) {
            return;
        }

        $source->forceFill(['index_status' => $status, 'indexed_at' => $status === IndexStatus::Indexed ? now() : null])->save();
        $this->announce($source, $kind);
    }

    private function announce(KbArticle|KbDocument $source, SourceKind $kind): void
    {
        LiveUpdates::knowledge($source->tenant_id, $kind, $source->id);
    }
}
