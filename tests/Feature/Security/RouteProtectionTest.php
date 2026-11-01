<?php

declare(strict_types=1);

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

/**
 * Rutas públicas aceptadas, cada una con su motivo. Todo lo demás exige autenticación.
 *
 * @var list<string>
 */
const PUBLIC_ROUTES = [
    '/',        // redirige a la bandeja o a la cuenta, que sí exigen sesión
    'up',       // chequeo de salud sin datos
    'login',    // inicio de sesión
    'logout',   // cierra la sesión actual; sin sesión no hace nada
    'invitations/{token}',      // aceptar una invitación; el token de un solo uso es la credencial y hay límite de intentos
    'register',                 // alta de una empresa nueva; solo para visitantes y con límite de intentos
    'forgot-password',          // pide el enlace de recuperación; responde igual exista o no la cuenta
    'reset-password/{token}',   // pantalla a la que llega el enlace del correo
    'reset-password',           // cambia la contraseña con un enlace de un solo uso
    'two-factor-challenge',     // segundo paso del inicio de sesión: exige haber pasado la contraseña (sesión de desafío)
];

it('toda ruta exige autenticación salvo las públicas listadas', function (): void {
    $unprotected = collect(Route::getRoutes()->getRoutes())
        ->reject(fn (LaravelRoute $route): bool => in_array($route->uri(), PUBLIC_ROUTES, true))
        ->filter(fn (LaravelRoute $route): bool => ! collect($route->gatherMiddleware())
            ->contains(fn (mixed $middleware): bool => is_string($middleware) && ($middleware === 'auth' || str_starts_with($middleware, 'auth:') || str_contains($middleware, 'Authenticate'))))
        ->map(fn (LaravelRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    expect($unprotected)->toBe([]);
});

it('el disco local no sirve ni recibe archivos por rutas públicas', function (): void {
    $uris = collect(Route::getRoutes()->getRoutes())->map(fn (LaravelRoute $route): string => $route->uri());

    expect($uris->contains('storage/{path}'))->toBeFalse();
});
