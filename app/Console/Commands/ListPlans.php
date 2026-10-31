<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\Tenant;
use App\Plans\Limit;
use Illuminate\Console\Command;

class ListPlans extends Command
{
    protected $signature = 'plans:list';

    protected $description = 'Muestra el catálogo de planes y el plan de cada empresa.';

    public function handle(): int
    {
        $plans = Plan::query()->orderBy('id')->get();

        $this->table(
            ['Clave', 'Nombre', 'Por defecto', 'Activo', 'Agentes', 'Canales', 'Tickets/mes', 'Créditos IA/mes'],
            $plans->map(fn (Plan $plan): array => [
                $plan->slug,
                $plan->name,
                $plan->is_default ? 'sí' : '',
                $plan->is_active ? 'sí' : 'no',
                ...array_map(fn (Limit $limit): string => (string) ($plan->limitFor($limit) ?? 'sin límite'), Limit::cases()),
            ])->all(),
        );

        $this->newLine();

        $this->table(
            ['Empresa', 'Nombre', 'Plan'],
            Tenant::query()->orderBy('id')->get()->map(fn (Tenant $tenant): array => [
                $tenant->id,
                $tenant->name,
                $plans->firstWhere('id', $tenant->plan_id)->slug ?? '?',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
