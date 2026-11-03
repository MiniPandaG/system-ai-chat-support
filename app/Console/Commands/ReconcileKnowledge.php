<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Knowledge\Reindexer;
use App\Knowledge\Vector\IndexUnavailable;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Alinea el índice vectorial con la base de datos: quita del índice lo que ya no existe y vuelve a indexar lo que falta.
 * Solo muestra cuántas cosas tocó, nunca su contenido. Es seguro repetirlo.
 */
class ReconcileKnowledge extends Command
{
    protected $signature = 'knowledge:reconcile {--tenant= : Solo la empresa con este identificador}';

    protected $description = 'Alinea el índice vectorial con la base de datos';

    public function handle(TenantContext $context, Reindexer $reindexer): int
    {
        $only = $this->option('tenant');

        if ($only !== null && (! is_string($only) || ! ctype_digit($only))) {
            $this->error('El identificador de la empresa debe ser un número.');

            return self::FAILURE;
        }

        $removed = 0;
        $requeued = 0;
        $failed = 0;

        Tenant::query()->when($only !== null, fn ($query) => $query->whereKey((int) $only))->each(function (Tenant $tenant) use ($context, $reindexer, &$removed, &$requeued, &$failed): void {
            try {
                $result = $context->run($tenant, fn (): array => $reindexer->reconcile());
                $removed += $result['removed'];
                $requeued += $result['requeued'];
            } catch (IndexUnavailable) {
                $failed++;
            }
        });

        $this->info("Quitados del índice: {$removed}. Fuentes encoladas para indexar: {$requeued}.");

        if ($failed > 0) {
            $this->error("No se pudo revisar el índice de {$failed} empresas: la base vectorial no responde.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
