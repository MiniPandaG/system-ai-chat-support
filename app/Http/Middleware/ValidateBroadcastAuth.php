<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La petición de autorización de un canal la arma el navegador: antes de que llegue al código que firma, se exige que
 * `socket_id` y `channel_name` tengan la forma exacta que envía el cliente de Pusher. Lo demás recibe el mismo 403 que un
 * canal no autorizado: no se distingue «mal formado» de «prohibido» y no hay errores internos que revelar.
 */
class ValidateBroadcastAuth
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $socket = $request->input('socket_id');
        $channel = $request->input('channel_name');

        abort_unless(
            is_string($socket) && preg_match('/\A\d{1,20}\.\d{1,20}\z/', $socket) === 1
            && is_string($channel) && preg_match('/\Aprivate-[A-Za-z0-9._-]{1,120}\z/', $channel) === 1,
            403,
        );

        return $next($request);
    }
}
