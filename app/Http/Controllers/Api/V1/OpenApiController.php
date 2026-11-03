<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Api\OpenApiSpec;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** La especificación OpenAPI de la API v1, para herramientas y para la página de documentación. */
class OpenApiController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(OpenApiSpec::build(), 200, ['Cache-Control' => 'public, max-age=300'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
