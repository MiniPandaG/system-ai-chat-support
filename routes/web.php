<?php

declare(strict_types=1);

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\AcceptInvitationController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\TeamController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/account');

// Registro de una empresa nueva con su administrador. Solo para visitantes y con límite de intentos.
// Aceptar una invitación: pública (quien llega aún no tiene cuenta) y protegida por el token de un solo uso.
Route::middleware(['guest', 'throttle:invitation'])->group(function (): void {
    Route::get('/invitations/{token}', [AcceptInvitationController::class, 'show'])->name('invitations.show');
    Route::post('/invitations/{token}', [AcceptInvitationController::class, 'store'])->name('invitations.accept');
});

Route::middleware('guest')->group(function (): void {
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:register');
});

// Cuenta: sesión iniciada, correo verificado y, para administradores de la plataforma, 2FA activo.
Route::middleware(['auth', 'verified', 'two-factor.required'])->group(function (): void {
    Route::get('/account', AccountController::class)->name('account');

    // Equipo: solo administradores de la empresa (UserPolicy). El miembro se busca dentro de la empresa del usuario.
    Route::get('/settings/team', [TeamController::class, 'index'])->name('team');
    Route::put('/settings/team/members/{member}/role', [TeamController::class, 'updateRole'])->whereNumber('member')->name('team.members.role');
    Route::post('/settings/team/members/{member}/deactivate', [TeamController::class, 'deactivate'])->whereNumber('member')->name('team.members.deactivate');
    Route::post('/settings/team/members/{member}/activate', [TeamController::class, 'activate'])->whereNumber('member')->name('team.members.activate');

    // Invitaciones: la invitación se resuelve con el filtro automático, así que una de otra empresa da 404.
    Route::post('/settings/team/invitations', [InvitationController::class, 'store'])->name('team.invitations.store');
    Route::post('/settings/team/invitations/{invitation}/resend', [InvitationController::class, 'resend'])->whereNumber('invitation')->name('team.invitations.resend');
    Route::post('/settings/team/invitations/{invitation}/revoke', [InvitationController::class, 'revoke'])->whereNumber('invitation')->name('team.invitations.revoke');
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
