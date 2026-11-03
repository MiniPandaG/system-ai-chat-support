<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Api\OpenApiSpec;
use App\Models\ApiKey;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La documentación interactiva de la API (RF-26), solo para administradores como la pantalla de llaves. Se dibuja a partir
 * de la misma especificación que sirve /api/v1/openapi.json.
 */
class ApiDocsController extends Controller
{
    public function __invoke(): Response
    {
        Gate::authorize('manage', ApiKey::class);

        return Inertia::render('settings/ApiDocs', [
            'copy' => __('api.docs'),
            'spec' => OpenApiSpec::build(),
            'base' => url('/api/v1'),
        ]);
    }
}
