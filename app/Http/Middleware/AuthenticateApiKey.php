<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Api\ApiKeys;
use App\Models\ApiKey;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reconoce la llave de API de la cabecera `Authorization: Bearer`. Una llave con mala forma, de una empresa que no
 * existe, desconocida, revocada o caducada recibe el mismo 401: quien llama no puede distinguir qué falló. La empresa
 * sale de la llave y se fija antes de buscarla, así la consulta ya lleva el filtro de empresa; nada de la petición decide
 * la empresa. Quien acumula intentos fallidos desde una misma dirección recibe 429 antes de comprobar nada.
 */
class AuthenticateApiKey
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $failures = 'api-auth-fail:'.$request->ip();

        if (RateLimiter::tooManyAttempts($failures, config()->integer('api.limits.failed_auth_per_ip_per_minute'))) {
            abort(429, 'api.errors.rate_limited', ['Retry-After' => (string) max(1, RateLimiter::availableIn($failures))]);
        }

        $parsed = ApiKeys::parse($request->bearerToken());
        $tenant = $parsed === null ? null : Tenant::query()->find($parsed['tenant_id']);

        if ($parsed === null || $tenant === null) {
            $this->reject($failures);
        }

        $this->context->set($tenant);
        $key = ApiKey::query()->where('key_hash', $parsed['hash'])->first();

        if ($key === null || ! $key->isUsable()) {
            $this->reject($failures);
        }

        // Último uso: se anota como mucho una vez por minuto para no escribir en cada petición.
        if ($key->last_used_at === null || $key->last_used_at->diffInSeconds(now()) >= 60) {
            $key->forceFill(['last_used_at' => now()])->save();
        }

        $request->attributes->set('api_key', $key);

        return $next($request);
    }

    private function reject(string $failures): never
    {
        RateLimiter::hit($failures, 60);
        $this->context->set(null);

        abort(401, '', ['WWW-Authenticate' => 'Bearer']);
    }
}
