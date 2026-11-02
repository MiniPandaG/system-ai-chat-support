<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\WidgetSession;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Borra las sesiones anónimas del widget que ya caducaron. Una sesión caducada no sirve para nada (el token se rechaza),
 * así que solo queda ocupando espacio. La conversación y el cliente no se tocan: siguen en la bandeja del equipo.
 */
class PruneWidgetSessions extends Command
{
    protected $signature = 'widget:prune {--days=1 : Solo las que llevan al menos tantos días caducadas}';

    protected $description = 'Borra las sesiones anónimas del widget que caducaron';

    public function handle(TenantContext $context): int
    {
        $limit = now()->subDays(max(0, (int) $this->option('days')));
        $deleted = 0;

        Tenant::query()->each(function (Tenant $tenant) use ($context, $limit, &$deleted): void {
            $context->run($tenant, function () use ($limit, &$deleted): void {
                $expired = WidgetSession::query()->where('expires_at', '<=', $limit);
                $deleted += $expired->count();
                $expired->delete();
            });
        });

        $this->info("Sesiones borradas: {$deleted}.");

        return self::SUCCESS;
    }
}
