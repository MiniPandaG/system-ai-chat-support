<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\WidgetSession;
use App\Tenancy\TenantContext;
use App\Widget\Origins;
use App\Widget\SessionToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reconoce al visitante del widget por el token que envía en la cabecera `Authorization`. Un token con mala forma, de
 * una empresa que no existe, sin sesión, caducado o de una empresa cuyo widget está apagado recibe el mismo 401: quien
 * escribe no puede distinguir qué falló. La empresa se fija antes de buscar la sesión, así que la consulta ya sale con el
 * filtro de empresa. Con `optional` la ausencia del token es válida (el primer mensaje abre la sesión); un token enviado
 * pero inválido sigue siendo un 401.
 */
class AuthenticateWidgetSession
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Origins $origins,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $token = $request->bearerToken();

        if ($token === null && $mode === 'optional') {
            return $next($request);
        }

        $parsed = SessionToken::parse($token);
        $tenant = $parsed === null ? null : Tenant::query()->find($parsed['tenant_id']);

        abort_if($parsed === null || $tenant === null || ! $this->origins->isEnabled($tenant), 401);

        $this->context->set($tenant);
        $session = WidgetSession::query()->where('token_hash', $parsed['hash'])->first();

        abort_if($session === null || $session->expires_at->isPast(), 401);

        $request->attributes->set('widget_tenant', $tenant);
        $request->attributes->set('widget_session', $session);

        return $next($request);
    }
}
