<?php

declare(strict_types=1);

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Tenancy\TenantContext;
use App\Widget\Origins;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * El logo de la empresa para el widget. El visitante no tiene sesión, así que este es el único archivo de la empresa
 * que se entrega por su llave pública: es una imagen de marca (ya re-codificada al subirla), nunca un adjunto. Sin logo,
 * con una llave desconocida o con el widget apagado responde lo mismo: 404.
 */
class LogoController extends Controller
{
    public function show(Request $request, Origins $origins, TenantContext $context): Response
    {
        $key = $request->query('key');

        abort_unless(is_string($key) && preg_match('/\A[A-Za-z0-9]{32}\z/', $key) === 1, 404);

        $tenant = Tenant::query()->where('widget_key', $key)->first();
        abort_if($tenant === null || ! $origins->isEnabled($tenant), 404);

        $file = $context->run($tenant, fn () => TenantBranding::query()->first()?->logo());
        abort_if($file === null, 404);

        $disk = Storage::disk($file->disk);
        abort_unless($disk->exists($file->path), 404);

        return $disk->response($file->path, 'logo.png', [
            'Content-Type' => $file->mime,
            'X-Content-Type-Options' => 'nosniff',
            // El enlace lleva el identificador del archivo (v): cambiar el logo cambia la dirección, así que puede cachearse.
            'Cache-Control' => 'public, max-age=86400',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
