<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AddWidgetOrigin;
use App\Actions\RemoveWidgetOrigin;
use App\Actions\UpdateWidgetAppearance;
use App\Http\Requests\StoreWidgetOriginRequest;
use App\Http\Requests\UpdateWidgetAppearanceRequest;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Models\User;
use App\Models\WidgetOrigin;
use App\Tenancy\TenantContext;
use App\Widget\Origins;
use App\Widget\WidgetCorner;
use App\Widget\WidgetMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El chat web de la empresa: el código para pegar en su sitio y los sitios donde puede mostrarse. Solo administradores
 * (WidgetOriginPolicy). Los sitios se leen y se borran dentro de la empresa, así que uno de otra empresa da 404.
 */
class WidgetSettingsController extends Controller
{
    public function show(TenantContext $context, Origins $origins): Response
    {
        Gate::authorize('manage', WidgetOrigin::class);
        $tenant = $this->tenant($context);

        $branding = TenantBranding::query()->first();

        return Inertia::render('settings/Widget', [
            'appearance' => [
                'mode' => ($branding === null ? WidgetMode::Light : $branding->widget_mode)->value,
                'corner' => ($branding === null ? WidgetCorner::Right : $branding->widget_corner)->value,
            ],
            'copy' => __('widget.page'),
            'snippet' => $this->snippet($tenant),
            'enabled' => $origins->isEnabled($tenant),
            'origins' => WidgetOrigin::query()->orderBy('origin')->get()
                ->map(static fn (WidgetOrigin $row): array => ['id' => $row->id, 'origin' => $row->origin])
                ->all(),
            'max' => config()->integer('widget.max_origins'),
        ]);
    }

    public function store(StoreWidgetOriginRequest $request, AddWidgetOrigin $add, TenantContext $context): RedirectResponse
    {
        $origin = $request->normalizedOrigin();
        abort_if($origin === null, 422);

        $add($this->user($request), $this->tenant($context), $origin);

        return back()->with('status', __('widget.page.added', ['origin' => $origin]));
    }

    public function updateAppearance(UpdateWidgetAppearanceRequest $request, UpdateWidgetAppearance $update): RedirectResponse
    {
        $update($this->user($request), $request->widgetMode(), $request->widgetCorner());

        return back()->with('status', __('widget.page.appearance_saved'));
    }

    public function destroy(Request $request, WidgetOrigin $origin, RemoveWidgetOrigin $remove): RedirectResponse
    {
        Gate::authorize('manage', WidgetOrigin::class);

        $value = $origin->origin;
        $remove($this->user($request), $origin);

        return back()->with('status', __('widget.page.removed', ['origin' => $value]));
    }

    /** El código que se pega en el sitio: la dirección del script de esta instalación y la llave pública de la empresa. */
    private function snippet(Tenant $tenant): string
    {
        return '<script src="'.url('/widget.js').'" data-key="'.$tenant->widget_key.'" async></script>';
    }

    private function tenant(TenantContext $context): Tenant
    {
        $tenant = $context->current();
        abort_if($tenant === null, 404);

        return $tenant;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_if($user === null, 401);

        return $user;
    }
}
