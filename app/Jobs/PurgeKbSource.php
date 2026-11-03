<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Knowledge\Indexer;
use App\Knowledge\SourceKind;
use App\Knowledge\Vector\IndexUnavailable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Quita del índice todo lo de una fuente que se despublicó o se borró. Es idempotente y trabaja con la empresa que tenía
 * el contexto al encolarlo. Si no llega a quitarse, lo que quede en el índice nunca se muestra (la búsqueda comprueba cada
 * resultado contra la base) y `knowledge:reconcile` lo limpia.
 */
class PurgeKbSource implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public function __construct(public readonly string $kind, public readonly int $sourceId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    /**
     * @throws IndexUnavailable
     */
    public function handle(Indexer $indexer): void
    {
        $indexer->purge(SourceKind::from($this->kind), $this->sourceId);
    }
}
