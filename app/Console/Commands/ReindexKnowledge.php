<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Knowledge\Reindexer;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Vuelve a indexar todo lo publicado. Hace falta al cambiar de modelo de embeddings (los vectores viejos ya no sirven) o
 * tras perder el índice. Es seguro repetirlo: cada fuente rehace lo suyo.
 */
class ReindexKnowledge extends Command
{
    protected $signature = 'knowledge:reindex {--tenant= : Solo la empresa con este identificador}';

    protected $description = 'Vuelve a indexar todo el conocimiento publicado';

    public function handle(TenantContext $context, Reindexer $reindexer): int
    {
        $only = $this->option('tenant');
        $count = 0;

        if ($only !== null && (! is_string($only) || ! ctype_digit($only))) {
            $this->error('El identificador de la empresa debe ser un número.');

            return self::FAILURE;
        }

        Tenant::query()->when($only !== null, fn ($query) => $query->whereKey((int) $only))->each(function (Tenant $tenant) use ($context, $reindexer, &$count): void {
            $count += $context->run($tenant, fn (): int => $reindexer->reindexAll());
        });

        $this->info("Fuentes encoladas para indexar: {$count}.");

        return self::SUCCESS;
    }
}
