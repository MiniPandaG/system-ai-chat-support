<?php

declare(strict_types=1);

use App\Http\Controllers\AccountController;
use App\Http\Controllers\ApiDocsController;
use App\Http\Controllers\ApiKeyController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\AcceptInvitationController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\CustomerSearchController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TicketMessageController;
use App\Http\Controllers\TicketNoteController;
use App\Http\Controllers\TicketTagController;
use App\Http\Controllers\TicketTypingController;
use App\Http\Controllers\TicketViewController;
use App\Http\Controllers\WidgetSettingsController;
use Illuminate\Support\Facades\Route;

// La entrada decide a dónde ir según quién sea; sin sesión, el inicio de sesión lo pide después.
Route::get('/', HomeController::class);

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

    // Bandeja y tickets: todo el equipo activo los ve; administradores y agentes los crean y modifican (TicketPolicy).
    // El ticket se resuelve con el filtro automático de empresa, así que uno de otra empresa da 404.
    Route::get('/tickets', [TicketController::class, 'index'])->middleware('throttle:tickets-list')->name('tickets');
    Route::get('/tickets/page', [TicketController::class, 'page'])->middleware('throttle:tickets-list')->name('tickets.page');
    Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->middleware('throttle:tickets-list')->whereNumber('ticket')->name('tickets.show');
    Route::post('/tickets/views', [TicketViewController::class, 'store'])->middleware('throttle:tickets-write')->name('tickets.views.store');
    Route::delete('/tickets/views/{view}', [TicketViewController::class, 'destroy'])->middleware('throttle:tickets-write')->whereNumber('view')->name('tickets.views.destroy');
    Route::post('/tickets', [TicketController::class, 'store'])->middleware('throttle:tickets-create')->name('tickets.store');
    Route::patch('/tickets/{ticket}', [TicketController::class, 'update'])->middleware('throttle:tickets-write')->whereNumber('ticket')->name('tickets.update');
    // Dentro del ticket: respuestas al cliente, notas internas (solo equipo) y etiquetas.
    Route::post('/tickets/{ticket}/messages', [TicketMessageController::class, 'store'])->middleware('throttle:tickets-message')->whereNumber('ticket')->name('tickets.messages.store');
    Route::post('/tickets/{ticket}/typing', [TicketTypingController::class, 'store'])->middleware('throttle:tickets-typing')->whereNumber('ticket')->name('tickets.typing');
    Route::post('/tickets/{ticket}/notes', [TicketNoteController::class, 'store'])->middleware('throttle:tickets-message')->whereNumber('ticket')->name('tickets.notes.store');
    Route::post('/tickets/{ticket}/tags', [TicketTagController::class, 'store'])->middleware('throttle:tickets-write')->whereNumber('ticket')->name('tickets.tags.store');
    Route::delete('/tickets/{ticket}/tags/{tag}', [TicketTagController::class, 'destroy'])->middleware('throttle:tickets-write')->whereNumber(['ticket', 'tag'])->name('tickets.tags.destroy');
    // Descarga de un adjunto: URL firmada que caduca, sesión, empresa dueña y solo si el antivirus lo declaró limpio.
    Route::get('/attachments/{attachment}', [AttachmentController::class, 'download'])->middleware('signed')->where('attachment', '[0-9a-z]{26}')->name('attachments.download');
    Route::get('/customers/search', CustomerSearchController::class)->middleware('throttle:tickets-search')->name('customers.search');

    // Descarga de archivos de la empresa: URL firmada que caduca, sesión y empresa dueña (FileStore::temporaryUrl).
    Route::get('/files/{file}', [FileController::class, 'show'])->middleware('signed')->where('file', '[0-9a-z]{26}')->name('files.show');

    // Equipo: solo administradores de la empresa (UserPolicy). El miembro se busca dentro de la empresa del usuario.
    Route::get('/settings/team', [TeamController::class, 'index'])->name('team');
    Route::put('/settings/team/members/{member}/role', [TeamController::class, 'updateRole'])->whereNumber('member')->name('team.members.role');
    Route::post('/settings/team/members/{member}/deactivate', [TeamController::class, 'deactivate'])->whereNumber('member')->name('team.members.deactivate');
    Route::post('/settings/team/members/{member}/activate', [TeamController::class, 'activate'])->whereNumber('member')->name('team.members.activate');

    // Plan y uso: solo lectura para administradores (PlanPolicy). El plan lo cambia la plataforma con plans:assign.
    Route::get('/settings/plan', [PlanController::class, 'show'])->name('plan');

    // Marca de la empresa: solo administradores (TenantBrandingPolicy).
    Route::get('/settings/branding', [BrandingController::class, 'show'])->name('branding');
    Route::put('/settings/branding/name', [BrandingController::class, 'updateName'])->name('branding.name');
    Route::put('/settings/branding/colors', [BrandingController::class, 'updateColors'])->name('branding.colors.update');
    Route::delete('/settings/branding/colors', [BrandingController::class, 'resetColors'])->name('branding.colors.reset');
    Route::post('/settings/branding/palette/check', [BrandingController::class, 'checkPalette'])->middleware('throttle:palette-check')->name('branding.palette.check');
    Route::post('/settings/branding/logo', [BrandingController::class, 'storeLogo'])->name('branding.logo.store');
    Route::delete('/settings/branding/logo', [BrandingController::class, 'destroyLogo'])->name('branding.logo.destroy');

    // Llaves de API: solo administradores (ApiKeyPolicy). La llave completa se entrega una sola vez.
    Route::get('/settings/api-keys', [ApiKeyController::class, 'index'])->name('api-keys');
    Route::post('/settings/api-keys', [ApiKeyController::class, 'store'])->middleware('throttle:api-keys-manage')->name('api-keys.store');
    Route::post('/settings/api-keys/{apiKey}/rotate', [ApiKeyController::class, 'rotate'])->middleware('throttle:api-keys-manage')->whereNumber('apiKey')->name('api-keys.rotate');
    Route::delete('/settings/api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])->middleware('throttle:api-keys-manage')->whereNumber('apiKey')->name('api-keys.destroy');

    // Documentación interactiva de la API, solo administradores (ApiKeyPolicy).
    Route::get('/settings/api-docs', ApiDocsController::class)->name('api-docs');

    // Chat web: código para incrustar y sitios permitidos, solo administradores (WidgetOriginPolicy).
    Route::get('/settings/widget', [WidgetSettingsController::class, 'show'])->name('widget.settings');
    Route::put('/settings/widget/appearance', [WidgetSettingsController::class, 'updateAppearance'])->middleware('throttle:widget-settings')->name('widget.appearance.update');
    Route::post('/settings/widget/origins', [WidgetSettingsController::class, 'store'])->middleware('throttle:widget-settings')->name('widget.origins.store');
    Route::delete('/settings/widget/origins/{origin}', [WidgetSettingsController::class, 'destroy'])->middleware('throttle:widget-settings')->whereNumber('origin')->name('widget.origins.destroy');

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
