<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureTwoFactorForAdmins;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetTenantContext;
use App\Http\Middleware\ThrottleAccountEmails;
use App\Plans\LimitReached;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
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
        $middleware->alias(['two-factor.required' => EnsureTwoFactorForAdmins::class]);
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/account');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Pasar el límite del plan es un resultado esperado, no un error del sistema: no se reporta y se muestra al usuario.
        $exceptions->dontReport(LimitReached::class);
        $exceptions->render(fn (LimitReached $exception, Request $request) => $request->expectsJson()
            ? response()->json(['message' => $exception->getMessage(), 'limit' => $exception->limit->value], 422)
            : back()->withErrors(['limit' => $exception->getMessage()]));

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
