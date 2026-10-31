<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limita los envíos de correo de cuenta (recuperación y reenvío de verificación). La clave no depende de si la
 * cuenta existe, así que el límite no revela nada.
 */
class ThrottleAccountEmails
{
    private const ROUTES = ['password.email', 'verification.send'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route()?->getName();

        if (! is_string($route) || ! in_array($route, self::ROUTES, true)) {
            return $next($request);
        }

        $subject = $request->user()?->getAuthIdentifier() ?? mb_strtolower($request->string('email')->toString());
        $perAccount = "account-mail:{$route}:".hash('sha256', $request->ip().'|'.(is_scalar($subject) ? (string) $subject : ''));
        $perIp = "account-mail-ip:{$route}:".$request->ip();

        foreach ([[$perAccount, 3, 600], [$perIp, 20, 3600]] as [$key, $max, $decay]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);

                throw ValidationException::withMessages([
                    'email' => [trans('auth.throttle', ['minutes' => max(1, $minutes)])],
                ])->status(429);
            }

            RateLimiter::hit($key, $decay);
        }

        return $next($request);
    }
}
