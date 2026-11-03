<?php

declare(strict_types=1);

namespace App\Knowledge;

use App\Knowledge\Vector\IndexUnavailable;
use App\Knowledge\Vector\VectorIndex;
use App\Models\KbArticle;
use App\Models\KbChunk;
use App\Models\KbContext;
use App\Models\KbDocument;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Borra todo el conocimiento de una empresa (RS-18): artículos, documentos y sus archivos, fragmentos, contexto y el
 * índice vectorial entero. Es lo que usará el borrado de una empresa. Se puede repetir: primero cae el índice y después la
 * base, así que si algo falla a medias, volver a llamarlo termina el trabajo.
 */
class KnowledgePurge
{
    public function __construct(
        private readonly VectorIndex $index,
        private readonly TenantContext $context,
    ) {}

    /**
     * @throws IndexUnavailable
     */
    public function forTenant(Tenant $tenant): void
    {
        $this->index->dropTenant($tenant->id);

        $this->context->run($tenant, function () use ($tenant): void {
            DB::transaction(function (): void {
                KbChunk::query()->delete();
                KbDocument::query()->delete();
                KbArticle::query()->delete();
                KbContext::query()->delete();
            });

            // Lo que haya quedado en el disco, incluidos documentos que todavía estaban por revisar.
            Storage::disk(config()->string('files.disk'))->deleteDirectory("tenants/{$tenant->id}/knowledge");
        });
    }
}
