<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Http\Requests\SavePlanRequest;
use App\Models\Plan;
use App\Plans\Limit;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El catálogo de planes, administrado por la plataforma. El plan de fábrica es el que recibe una empresa nueva y
 * siempre debe existir y estar activo; una empresa que ya tiene un plan que se desactiva lo conserva (solo deja de
 * poder elegirse).
 */
class PlatformPlanController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('manage', Plan::class);

        return Inertia::render('platform/Plans', [
            'copy' => __('platform.plans'),
            'plans' => Plan::query()->withCount('tenants')->orderBy('id')->get()->map(function (Plan $plan): array {
                $limits = [];

                foreach (Limit::cases() as $limit) {
                    $limits[$limit->value] = $plan->limitFor($limit);
                }

                return [
                    'id' => $plan->id,
                    'slug' => $plan->slug,
                    'name' => $plan->name,
                    'is_default' => $plan->is_default,
                    'is_active' => $plan->is_active,
                    'tenants' => $plan->tenants_count,
                    'limits' => $limits,
                ];
            })->all(),
        ]);
    }

    public function store(SavePlanRequest $request, AuditLogger $audit): RedirectResponse
    {
        $plan = new Plan;
        $plan->forceFill([
            'slug' => $request->string('slug')->toString(),
            'name' => trim($request->string('name')->toString()),
            'is_default' => false,
            'is_active' => $request->boolean('is_active'),
            ...$this->limits($request),
        ])->save();

        $audit->record(AuditEvent::PlanCreated, $request->user()?->id, ['plan_id' => $plan->id, 'slug' => $plan->slug]);

        return back()->with('status', __('platform.plans.created', ['plan' => $plan->name]));
    }

    public function update(SavePlanRequest $request, Plan $plan, AuditLogger $audit): RedirectResponse
    {
        // El plan que reciben las empresas nuevas no puede desactivarse: el registro dejaría de funcionar.
        if ($plan->is_default && ! $request->boolean('is_active')) {
            return back()->withErrors(['is_active' => __('platform.errors.default_inactive')]);
        }

        $plan->forceFill([
            'name' => trim($request->string('name')->toString()),
            'is_active' => $request->boolean('is_active'),
            ...$this->limits($request),
        ]);

        $changed = array_keys($plan->getDirty());

        if ($changed !== []) {
            $plan->save();
            $audit->record(AuditEvent::PlanUpdated, $request->user()?->id, ['plan_id' => $plan->id, 'fields' => implode(',', $changed)]);
        }

        return back()->with('status', __('platform.plans.updated', ['plan' => $plan->name]));
    }

    public function makeDefault(Request $request, Plan $plan, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('manage', Plan::class);

        if (! $plan->is_active) {
            return back()->withErrors(['is_default' => __('platform.errors.default_needs_active')]);
        }

        if (! $plan->is_default) {
            DB::transaction(function () use ($plan): void {
                Plan::query()->where('is_default', true)->update(['is_default' => false]);
                $plan->forceFill(['is_default' => true])->save();
            });

            $audit->record(AuditEvent::PlanDefaultChanged, $request->user()?->id, ['plan_id' => $plan->id, 'slug' => $plan->slug]);
        }

        return back()->with('status', __('platform.plans.default_set', ['plan' => $plan->name]));
    }

    /**
     * @return array<string, int|null>
     */
    private function limits(SavePlanRequest $request): array
    {
        $limits = [];

        foreach (Limit::cases() as $limit) {
            $value = $request->input($limit->column());
            $limits[$limit->column()] = is_numeric($value) ? (int) $value : null;
        }

        return $limits;
    }
}
