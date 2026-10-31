<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Plans\Limit;
use App\Plans\PlanLimits;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pantalla de uso del plan (solo lectura). Todo se calcula en el servidor con la empresa en contexto; la pantalla no
 * recibe nada del cliente.
 */
class PlanController extends Controller
{
    /**
     * @param  array{members: int, invitations: int}  $breakdown
     */
    private function seatsDetail(array $breakdown): string
    {
        return trans_choice('plans.page.members', $breakdown['members'], ['count' => $breakdown['members']])
            .' y '.trans_choice('plans.page.invitations', $breakdown['invitations'], ['count' => $breakdown['invitations']]).'.';
    }

    public function show(TenantContext $context, PlanLimits $limits): Response
    {
        Gate::authorize('viewAny', Plan::class);

        $tenant = $context->current();
        abort_if($tenant === null, 404);

        $snapshot = $limits->snapshot($tenant);
        $items = [];

        foreach (Limit::cases() as $limit) {
            $items[] = [
                'key' => $limit->value,
                'monthly' => $limit->isMonthly(),
                // Solo los puestos de agente se miden hoy; los demás recursos llegan con sus módulos.
                'measured' => $limit === Limit::Agents,
                ...$snapshot[$limit->value],
            ];
        }

        return Inertia::render('settings/Plan', [
            'copy' => __('plans.page'),
            'plan' => ['name' => $limits->planOf($tenant)->name],
            'limits' => $items,
            'seatsDetail' => $this->seatsDetail($limits->agentBreakdown($tenant)),
        ]);
    }
}
