<?php

declare(strict_types=1);

namespace App\Http\Controllers\Widget;

use App\Branding\Palette;
use App\Http\Controllers\Controller;
use App\Http\Middleware\SecurityHeaders;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Tenancy\TenantContext;
use App\Widget\Origins;
use App\Widget\WidgetCorner;
use App\Widget\WidgetMode;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * La página que el script incrustable pone dentro de su iframe. Solo se sirve si la llave es de una empresa con el widget
 * activo y si el sitio que la incrusta es uno de los que esa empresa permite. La defensa que de verdad impide incrustarla
 * en otro sitio es `frame-ancestors` en la política de contenido (la hace cumplir el navegador); la comprobación de
 * `parent` evita servir la página, y nombrar a la empresa, a quien claramente no está autorizado.
 */
class FrameController extends Controller
{
    public function show(Request $request, Origins $origins, TenantContext $context): Response
    {
        $key = $request->query('key');

        abort_unless(is_string($key) && preg_match('/\A[A-Za-z0-9]{32}\z/', $key) === 1, 404);

        $tenant = Tenant::query()->where('widget_key', $key)->first();
        abort_if($tenant === null || ! $origins->isEnabled($tenant), 404);

        $parent = Origins::normalize(is_string($request->query('parent')) ? $request->query('parent') : '');
        $allowed = $origins->for($tenant);
        abort_unless($parent !== null && in_array($parent, $allowed, true), 403);

        $request->attributes->set(SecurityHeaders::FRAME_ANCESTORS, $allowed);

        $branding = $context->run($tenant, fn (): ?TenantBranding => TenantBranding::query()->first());

        return response()
            ->view('widget.frame', [
                'config' => $this->config($tenant, $key, $parent, $branding),
                // Solo colores de la lista cerrada, en #RRGGBB, y con un selector que ya conoce el sistema.
                'brandingCss' => Palette::css($branding?->widgetVariables() ?? [], 'html:root[data-theme]'),
            ])
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex');
    }

    /**
     * Lo que el widget necesita saber, como texto JSON para un atributo de datos (Blade lo escapa).
     *
     * @return array<string, mixed>
     */
    private function config(Tenant $tenant, string $key, string $parent, ?TenantBranding $branding): array
    {
        /** @var array<string, string> $copy */
        $copy = __('widget.ui');

        return [
            'key' => $key,
            'parent' => $parent,
            'company' => $tenant->name,
            'mode' => ($branding === null ? WidgetMode::Light : $branding->widget_mode)->value,
            'corner' => ($branding === null ? WidgetCorner::Right : $branding->widget_corner)->value,
            // La dirección lleva el identificador del archivo: si cambia el logo, cambia la dirección y no queda uno viejo en la caché.
            'logo' => $branding?->logo_file_id === null ? null : '/widget/logo?key='.$key.'&v='.$branding->logo_file_id,
            'copy' => $copy,
        ];
    }
}
