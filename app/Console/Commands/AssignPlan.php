<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\Tenant;
use App\Plans\PlanAssigner;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;

/**
 * Cambia el plan de una empresa desde el servidor. El panel de la plataforma hace lo mismo con la misma acción
 * (`PlanAssigner`) y la misma auditoría.
 */
class AssignPlan extends Command
{
    protected $signature = 'plans:assign {tenant : Número de la empresa (ver plans:list)} {plan : Clave del plan, por ejemplo equipo}';

    protected $description = 'Asigna un plan a una empresa. Queda en la auditoría.';

    public function handle(PlanAssigner $assigner): int
    {
        $tenant = Tenant::query()->find((int) $this->argument('tenant'));

        if ($tenant === null) {
            $this->error('No existe una empresa con ese número. Usa plans:list para verlas.');

            return self::FAILURE;
        }

        $plan = Plan::query()->where('slug', (string) $this->argument('plan'))->first();

        if ($plan === null) {
            $this->error('No existe ese plan. Usa plans:list para ver las claves.');

            return self::FAILURE;
        }

        if (! $plan->is_active) {
            $this->error("El plan {$plan->slug} no está activo y no se puede asignar.");

            return self::FAILURE;
        }

        $current = Plan::query()->findOrFail($tenant->plan_id);

        if ($current->is($plan)) {
            $this->info("«{$tenant->name}» ya tiene el plan {$plan->slug}. No se cambia nada.");

            return self::SUCCESS;
        }

        if ($this->input->isInteractive() && ! confirm("¿Pasar «{$tenant->name}» del plan {$current->slug} al plan {$plan->slug}?", default: false)) {
            $this->warn('Cancelado: no se cambió nada.');

            return self::SUCCESS;
        }

        $assigner->assign($tenant, $plan, null, 'console');

        $this->info("Listo: «{$tenant->name}» ahora tiene el plan {$plan->slug}.");

        return self::SUCCESS;
    }
}
