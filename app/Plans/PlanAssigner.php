<?php

declare(strict_types=1);

namespace App\Plans;

use App\Enums\AuditEvent;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\AuditLogger;

/**
 * Único lugar donde se cambia el plan de una empresa. Lo usan la consola (plans:assign) y el panel de la plataforma,
 * para que los dos dejen la misma huella en la auditoría.
 */
class PlanAssigner
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  'console'|'web'  $via
     * @return bool Verdadero si el plan cambió; falso si la empresa ya lo tenía.
     */
    public function assign(Tenant $tenant, Plan $plan, ?int $actorId, string $via): bool
    {
        $current = Plan::query()->findOrFail($tenant->plan_id);

        if ($current->is($plan)) {
            return false;
        }

        $tenant->forceFill(['plan_id' => $plan->id])->save();

        $this->audit->record(AuditEvent::PlanAssigned, $actorId, [
            'tenant_id' => $tenant->id,
            'from' => $current->slug,
            'to' => $plan->slug,
            'via' => $via,
        ]);

        return true;
    }
}
