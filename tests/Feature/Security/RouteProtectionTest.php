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
    'widget/frame',             // la página del widget dentro del iframe; solo se sirve a los sitios que la empresa permite y con límite por IP
    'widget/logo',              // el logo de marca de una empresa con el widget activo; por llave pública, sin sesión, con límite por IP
    'widget/api/messages',      // el primer mensaje de un visitante anónimo abre su sesión; la llave pública, el límite y el tope del plan lo acotan
];

/** El visitante del widget se autentica con el token de su sesión (cabecera Authorization), no con la sesión del panel. */
const WIDGET_SESSION_MIDDLEWARE = 'widget.session';

it('toda ruta exige autenticación salvo las públicas listadas', function (): void {
    $unprotected = collect(Route::getRoutes()->getRoutes())
        ->reject(fn (LaravelRoute $route): bool => in_array($route->uri(), PUBLIC_ROUTES, true))
        ->filter(fn (LaravelRoute $route): bool => ! collect($route->gatherMiddleware())
            ->contains(fn (mixed $middleware): bool => is_string($middleware) && ($middleware === 'auth' || str_starts_with($middleware, 'auth:') || $middleware === WIDGET_SESSION_MIDDLEWARE || str_contains($middleware, 'Authenticate'))))
        ->map(fn (LaravelRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    expect($unprotected)->toBe([]);
});

it('el disco local no sirve ni recibe archivos por rutas públicas', function (): void {
    $uris = collect(Route::getRoutes()->getRoutes())->map(fn (LaravelRoute $route): string => $route->uri());

    expect($uris->contains('storage/{path}'))->toBeFalse();
});
