<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Api\ApiPermission;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige que la llave alcance el nivel que pide la ruta (`api.can:write`). Se comprueba en el servidor antes de resolver
 * lo que la ruta nombra, así una llave de lectura no puede ni averiguar si existe un ticket al que intenta escribir.
 */
class EnsureApiPermission
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $needed): Response
    {
        $key = $request->attributes->get('api_key');
        $level = ApiPermission::tryFrom($needed);

        abort_unless($key instanceof ApiKey && $level !== null && $key->permission->allows($level), 403);

        return $next($request);
    }
}
