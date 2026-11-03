<?php

declare(strict_types=1);

namespace App\Knowledge;

use App\Jobs\IndexKbSource;
use App\Jobs\PurgeKbSource;
use App\Models\KbArticle;
use App\Models\KbDocument;

/**
 * Pide que lo publicado entre al índice, o que lo despublicado salga. El trabajo se encola al confirmar la transacción:
 * si se deshace, no sale ninguno. Mientras tanto la fuente figura «indexando».
 */
class IndexQueue
{
    public function requestIndex(KbArticle|KbDocument $source): void
    {
        $source->forceFill(['index_status' => IndexStatus::Pending, 'indexed_at' => null])->save();

        IndexKbSource::dispatch(SourceKind::of($source)->value, $source->id)->afterCommit();
    }

    /** Lo despublicado ya no se indexa: se limpia su estado y se pide quitarlo del índice. */
    public function requestPurge(KbArticle|KbDocument $source): void
    {
        $source->forceFill(['index_status' => null, 'indexed_at' => null])->save();

        PurgeKbSource::dispatch(SourceKind::of($source)->value, $source->id)->afterCommit();
    }

    /** Lo borrado ya no tiene fila que actualizar: solo se pide quitarlo del índice. */
    public function requestPurgeById(SourceKind $kind, int $sourceId): void
    {
        PurgeKbSource::dispatch($kind->value, $sourceId)->afterCommit();
    }
}
