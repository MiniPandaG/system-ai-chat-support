<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fija la empresa de la petición a partir del usuario con sesión, nunca a partir de un dato que envíe el cliente.
 * El personal de la plataforma y los visitantes quedan sin empresa.
 */
class SetTenantContext
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $tenantId = $user instanceof User ? $user->tenant_id : null;

        $this->context->set($tenantId === null ? null : Tenant::query()->find($tenantId));

        return $next($request);
    }

    public function terminate(): void
    {
        $this->context->set(null);
    }
}
