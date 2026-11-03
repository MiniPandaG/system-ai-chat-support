<?php

declare(strict_types=1);

use App\Api\ApiErrors;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\AuthenticateWidgetSession;
use App\Http\Middleware\EnsureApiPermission;
use App\Http\Middleware\EnsureTwoFactorForAdmins;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\IdempotentRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetTenantContext;
use App\Http\Middleware\ThrottleAccountEmails;
use App\Http\Middleware\ValidateBroadcastAuth;
use App\Plans\LimitReached;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('widget')->prefix('widget')->group(base_path('routes/widget.php'));
            Route::middleware('api')->prefix('api/v1')->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->web(append: [
            EnsureUserIsActive::class,
            SetTenantContext::class,
            HandleInertiaRequests::class,
            ThrottleAccountEmails::class,
        ]);
        // La empresa debe estar en contexto antes de resolver modelos de la ruta, o el filtro automático no la vería.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: SetTenantContext::class);
        $middleware->prependToPriorityList(before: SetTenantContext::class, prepend: EnsureUserIsActive::class);
        $middleware->alias([
            'two-factor.required' => EnsureTwoFactorForAdmins::class,
            'widget.session' => AuthenticateWidgetSession::class,
            'api.key' => AuthenticateApiKey::class,
            'api.can' => EnsureApiPermission::class,
            'api.idempotent' => IdempotentRequests::class,
        ]);
        // La API del widget no usa cookies, sesión ni CSRF: el visitante se identifica con un token (ver routes/widget.php).
        $middleware->group('widget', [SubstituteBindings::class]);
        // La API pública no usa cookies, sesión ni CSRF: la llave y su permiso se comprueban antes de resolver lo que nombra la ruta.
        $middleware->group('api', [SubstituteBindings::class]);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureApiPermission::class);
        // La llave se reconoce antes que el límite de peticiones (que cuenta por llave) y que el permiso.
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: AuthenticateApiKey::class);
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/');
    })
    // Autorización de los canales de tiempo real: la misma sesión, correo verificado y 2FA que el panel, y un límite por persona.
    ->withBroadcasting(__DIR__.'/../routes/channels.php', [
        'middleware' => ['web', 'auth', 'verified', 'two-factor.required', 'throttle:broadcast-auth', ValidateBroadcastAuth::class],
    ])
    ->withExceptions(function (Exceptions $exceptions): void {
        // Pasar el límite del plan es un resultado esperado, no un error del sistema: no se reporta y se muestra al usuario.
        $exceptions->dontReport(LimitReached::class);
        // La API responde siempre con su propio formato de error, sin detalles internos.
        $exceptions->render(fn (Throwable $exception, Request $request) => $request->is('api/v1/*') ? ApiErrors::render($exception) : null);
        $exceptions->render(fn (LimitReached $exception, Request $request) => $request->expectsJson()
            ? response()->json(['message' => $exception->getMessage(), 'limit' => $exception->limit->value], 422)
            : back()->withErrors(['limit' => $exception->getMessage()]));

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
