<?php

declare(strict_types=1);

use App\Http\Controllers\AccountController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/account');

// Cuenta: sesión iniciada, correo verificado y, para administradores de la plataforma, 2FA activo.
Route::middleware(['auth', 'verified', 'two-factor.required'])->group(function (): void {
    Route::get('/account', AccountController::class)->name('account');
});

// Seguridad: sin la exigencia de 2FA (aquí se activa) y con la contraseña confirmada hace poco.
Route::middleware(['auth', 'verified', 'password.confirm'])->group(function (): void {
    Route::get('/settings/security', SecurityController::class)->name('security');

    Route::put('/settings/password', [PasswordController::class, 'update'])
        ->middleware('throttle:password-change')
        ->name('settings.password.update');

    Route::delete('/settings/sessions', [SessionController::class, 'destroyOthers'])->name('settings.sessions.destroy-others');
    Route::delete('/settings/sessions/all', [SessionController::class, 'destroyAll'])->name('settings.sessions.destroy-all');
    Route::delete('/settings/sessions/{handle}', [SessionController::class, 'destroy'])
        ->where('handle', '[a-f0-9]{64}')
        ->name('settings.sessions.destroy');
});
