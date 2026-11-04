<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ChangeTenantPlanRequest;
use App\Models\Plan;
use App\Models\Tenant;
use App\Plans\Limit;
use App\Plans\PlanAssigner;
use App\Plans\PlanLimits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las empresas vistas desde la plataforma: quién es cada una, qué plan tiene y cuánto usa, con el cambio de plan.
 * Solo muestra cifras de uso; nunca tickets, mensajes ni datos de los clientes de ninguna empresa.
 */
class PlatformTenantController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request, PlanLimits $limits): Response
    {
        Gate::authorize('viewAny', Tenant::class);

        $search = mb_substr(trim($request->string('q')->toString()), 0, 80);

        $page = Tenant::query()
            ->with('plan')
            ->withCount(['users as members_count' => fn (Builder $query): Builder => $query->whereNull('disabled_at')])
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $tenants = collect($page->items())->map(function (Tenant $tenant) use ($limits): array {
            $plan = $tenant->plan ?? $limits->planOf($tenant);

            return [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'since_label' => $tenant->created_at?->isoFormat('D [de] MMM [de] YYYY'),
                'plan_id' => $tenant->plan_id,
                'plan_name' => $plan->name,
                'members' => $tenant->members_count,
                'agents' => ['used' => $limits->usage($tenant, Limit::Agents), 'limit' => $plan->limitFor(Limit::Agents)],
                'tickets' => ['used' => $limits->usage($tenant, Limit::TicketsPerMonth), 'limit' => $plan->limitFor(Limit::TicketsPerMonth)],
            ];
        })->all();

        return Inertia::render('platform/Tenants', [
            'copy' => __('platform.tenants'),
            'tenants' => $tenants,
            'plans' => Plan::query()->where('is_active', true)->orderBy('id')->get()->map(fn (Plan $plan): array => ['id' => $plan->id, 'name' => $plan->name])->all(),
            'search' => $search,
            'pagination' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function updatePlan(ChangeTenantPlanRequest $request, Tenant $tenant, PlanAssigner $assigner): RedirectResponse
    {
        $plan = Plan::query()->findOrFail($request->integer('plan_id'));

        $changed = $assigner->assign($tenant, $plan, $request->user()?->id, 'web');

        return back()->with('status', __($changed ? 'platform.tenants.plan_changed' : 'platform.tenants.plan_same', ['company' => $tenant->name, 'plan' => $plan->name]));
    }
}
