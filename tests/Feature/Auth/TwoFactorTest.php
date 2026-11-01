<?php

declare(strict_types=1);

use App\Auth\TwoFactorProvider;
use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\RecoveryCodes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

const CODE_FORMAT = '/^[A-HJ-NP-Z2-9]{8}-[A-HJ-NP-Z2-9]{8}$/';

/**
 * Código TOTP del usuario. El desplazamiento elige la ventana de 30 s: al confirmar se gasta la actual, y Fortify
 * rechaza un código ya usado, así que el inicio de sesión de las pruebas usa la siguiente.
 */
function otpFor(User $user, int $windowOffset = 0): string
{
    $secret = Fortify::currentEncrypter()->decrypt((string) $user->fresh()?->two_factor_secret);
    $engine = new Google2FA;

    return $engine->oathTotp($secret, intdiv(time(), 30) + $windowOffset);
}

/**
 * Deja al usuario con 2FA activo y confirmado y devuelve los códigos de recuperación en claro.
 *
 * @return list<string>
 */
function enrollTwoFactor(User $user): array
{
    app(EnableTwoFactorAuthentication::class)($user);
    app(ConfirmTwoFactorAuthentication::class)($user->fresh(), otpFor($user));

    /** @var list<string> $codes */
    $codes = session('recovery_codes');
    // El aviso de «códigos nuevos» solo dura una petición; aquí se da por visto.
    session()->forget('recovery_codes');
    $user->refresh();

    return $codes;
}

function platformAdmin(): User
{
    $admin = User::factory()->create();
    $admin->forceFill(['is_platform_admin' => true])->save();

    return $admin;
}

function recentPassword(): array
{
    return ['auth.password_confirmed_at' => time()];
}

it('activar el 2FA guarda el secreto cifrado y todavía no emite códigos', function (): void {
    $user = User::factory()->create();

    $this->withSession(recentPassword())->actingAs($user)->post('/user/two-factor-authentication')->assertRedirect();

    $fresh = $user->fresh();
    $raw = (string) DB::table('users')->where('id', $user->id)->value('two_factor_secret');
    $secret = Fortify::currentEncrypter()->decrypt((string) $fresh?->two_factor_secret);

    expect($secret)->toMatch('/^[A-Z2-7]{32}$/')
        ->and($raw)->not->toContain($secret)
        ->and($fresh?->two_factor_recovery_codes)->toBeNull()
        ->and($fresh?->two_factor_confirmed_at)->toBeNull()
        ->and($fresh?->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

it('pide confirmar la contraseña antes de tocar el 2FA', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/user/two-factor-authentication')->assertRedirect(route('password.confirm'));

    expect($user->fresh()?->two_factor_secret)->toBeNull();
});

it('la pantalla de seguridad entrega el QR como imagen y la clave solo mientras se activa', function (): void {
    $user = User::factory()->create();

    $this->withSession(recentPassword())->actingAs($user)->post('/user/two-factor-authentication');

    $this->withSession(recentPassword())->actingAs($user)->get('/settings/security')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $page->component('settings/Security')
                ->where('twoFactor.pending', true)
                ->where('twoFactor.enabled', false)
                ->where('setup.qr', fn (string $qr): bool => str_starts_with($qr, 'data:image/svg+xml;base64,')
                    && str_contains((string) base64_decode(substr($qr, 26), true), '<svg')
                    && ! str_contains((string) base64_decode(substr($qr, 26), true), '<script'))
                ->where('setup.key', fn (string $key): bool => preg_match('/^([A-Z2-7]{4} ){7}[A-Z2-7]{4}$/', $key) === 1);
        });
});

it('un código incorrecto no confirma el 2FA', function (): void {
    $user = User::factory()->create();
    app(EnableTwoFactorAuthentication::class)($user);

    $this->withSession(recentPassword())->actingAs($user)
        ->post('/user/confirmed-two-factor-authentication', ['code' => '000000'])
        ->assertSessionHasErrors('code', null, 'confirmTwoFactorAuthentication');

    expect($user->fresh()?->two_factor_confirmed_at)->toBeNull()
        ->and($user->fresh()?->two_factor_recovery_codes)->toBeNull();
});

it('confirmar el primer código activa el 2FA y entrega diez códigos una sola vez', function (): void {
    $user = User::factory()->create();
    app(EnableTwoFactorAuthentication::class)($user);

    $this->withSession(recentPassword())->actingAs($user)
        ->post('/user/confirmed-two-factor-authentication', ['code' => otpFor($user)])
        ->assertRedirect();

    $codes = session('recovery_codes');

    expect($codes)->toBeArray()->toHaveCount(10);
    foreach ($codes as $code) {
        expect($code)->toMatch(CODE_FORMAT);
    }
    expect(array_unique($codes))->toHaveCount(10)
        ->and($user->fresh()?->hasEnabledTwoFactorAuthentication())->toBeTrue()
        ->and(AuditLog::where('event', AuditEvent::TwoFactorConfirmed->value)->where('user_id', $user->id)->count())->toBe(1);
});

it('en la base solo quedan huellas de los códigos de recuperación, nunca los códigos', function (): void {
    $user = User::factory()->create();
    $codes = enrollTwoFactor($user);

    $raw = (string) DB::table('users')->where('id', $user->id)->value('two_factor_recovery_codes');
    $stored = json_decode($raw, true);

    expect($stored)->toBeArray()->toHaveCount(10);
    foreach ($stored as $fingerprint) {
        expect($fingerprint)->toMatch('/^[0-9a-f]{64}$/');
    }
    foreach ($codes as $code) {
        expect($raw)->not->toContain($code)->not->toContain(str_replace('-', '', $code));
    }
});

it('una vez emitidos, los códigos no se pueden volver a ver', function (): void {
    $user = User::factory()->create();
    enrollTwoFactor($user);

    $this->withSession(recentPassword())->actingAs($user)->get('/user/two-factor-recovery-codes')->assertNotFound();

    $this->withSession(recentPassword())->actingAs($user)->get('/settings/security')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('twoFactor.enabled', true)
            ->where('twoFactor.remainingCodes', 10)
            ->where('setup', null)
            ->where('flash.recovery_codes', null));
});

it('con 2FA activo, iniciar sesión lleva al desafío y no abre la sesión', function (): void {
    $password = Str::random(24);
    $user = User::factory()->password($password)->create();
    enrollTwoFactor($user);

    $this->post('/login', ['email' => $user->email, 'password' => $password])->assertRedirect('/two-factor-challenge');

    $this->assertGuest();
    $this->get('/account')->assertRedirect('/login');
    $this->get('/two-factor-challenge')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/TwoFactorChallenge')
            ->where('copy.use_recovery', 'Usar un código de recuperación en su lugar'));
});

it('el código TOTP correcto abre la sesión y la regenera', function (): void {
    $password = Str::random(24);
    $user = User::factory()->password($password)->create();
    enrollTwoFactor($user);

    $this->post('/login', ['email' => $user->email, 'password' => $password]);
    $before = session()->getId();

    $this->post('/two-factor-challenge', ['code' => otpFor($user, 1)])->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
    expect(session()->getId())->not->toBe($before);
});

it('un código TOTP incorrecto no abre la sesión y queda auditado', function (): void {
    $password = Str::random(24);
    $user = User::factory()->password($password)->create();
    enrollTwoFactor($user);

    $this->post('/login', ['email' => $user->email, 'password' => $password]);
    $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');

    $this->assertGuest();
    expect(AuditLog::where('event', AuditEvent::TwoFactorFailed->value)->where('user_id', $user->id)->count())->toBe(1);
});

it('un código TOTP ya usado no sirve una segunda vez', function (): void {
    $password = Str::random(24);
    $user = User::factory()->password($password)->create();
    enrollTwoFactor($user);
    $otp = otpFor($user, 1);

    $this->post('/login', ['email' => $user->email, 'password' => $password]);
    $this->post('/two-factor-challenge', ['code' => $otp])->assertRedirect('/');
    $this->post('/logout');

    $this->post('/login', ['email' => $user->email, 'password' => $password]);
    $this->post('/two-factor-challenge', ['code' => $otp])->assertSessionHasErrors('code');

    $this->assertGuest();
});

it('tres intentos de código bloquean la cuenta diez minutos, aunque el siguiente sea correcto', function (): void {
    $password = Str::random(24);
    $user = User::factory()->password($password)->create();
    enrollTwoFactor($user);

    $this->post('/login', ['email' => $user->email, 'password' => $password]);
    foreach (range(1, 3) as $_) {
        $this->post('/two-factor-challenge', ['code' => '000000']);
    }

    $this->post('/two-factor-challenge', ['code' => otpFor($user, 1)])->assertSessionHasErrors('code');
    expect(session('errors')?->first('code'))->toStartWith('Demasiados intentos');
    $this->assertGuest();

    // Volver a iniciar sesión no reinicia el contador: sigue ligado a la cuenta.
    $this->post('/login', ['email' => $user->email, 'password' => $password]);
    $this->post('/two-factor-challenge', ['code' => otpFor($user, 1)])->assertSessionHasErrors('code');
    $this->assertGuest();

    $this->travel(11)->minutes();
    $this->post('/login', ['email' => $user->email, 'password' => $password]);
    $this->post('/two-factor-challenge', ['code' => otpFor($user, 1)])->assertRedirect('/');
});

it('un código de recuperación entra una sola vez, con o sin guion y en minúsculas', function (): void {
    $password = Str::random(24);
    $user = User::factory()->password($password)->create();
    $codes = enrollTwoFactor($user);
    $code = $codes[0];

    $this->post('/login', ['email' => $user->email, 'password' => $password]);
    $this->post('/two-factor-challenge', ['recovery_code' => strtolower(str_replace('-', '', $code))])->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
    expect(RecoveryCodes::remaining($user->fresh()))->toBe(9);

    $this->post('/logout');
    $this->post('/login', ['email' => $user->email, 'password' => $password]);
    $this->post('/two-factor-challenge', ['recovery_code' => $code])->assertSessionHasErrors('recovery_code');
    $this->assertGuest();

    expect(AuditLog::where('event', AuditEvent::RecoveryCodeUsed->value)->count())->toBe(1)
        ->and(AuditLog::query()->get()->toJson())->not->toContain($code)->not->toContain(str_replace('-', '', $code));
});

it('los otros códigos de recuperación siguen sirviendo después de gastar uno', function (): void {
    $password = Str::random(24);
    $user = User::factory()->password($password)->create();
    $codes = enrollTwoFactor($user);

    $this->post('/login', ['email' => $user->email, 'password' => $password]);
    $this->post('/two-factor-challenge', ['recovery_code' => $codes[0]]);
    $this->post('/logout');

    $this->post('/login', ['email' => $user->email, 'password' => $password]);
    $this->post('/two-factor-challenge', ['recovery_code' => $codes[1]])->assertRedirect('/');
    expect(RecoveryCodes::remaining($user->fresh()))->toBe(8);
});

it('regenerar los códigos invalida los anteriores y entrega diez nuevos', function (): void {
    $password = Str::random(24);
    $user = User::factory()->password($password)->create();
    $old = enrollTwoFactor($user);

    $this->withSession(recentPassword())->actingAs($user)->post('/user/two-factor-recovery-codes')->assertRedirect();
    $new = session('recovery_codes');

    expect($new)->toBeArray()->toHaveCount(10)
        ->and(array_intersect($old, $new))->toBe([])
        ->and(AuditLog::where('event', AuditEvent::RecoveryCodesGenerated->value)->count())->toBe(1);

    auth()->logout();
    $this->post('/login', ['email' => $user->email, 'password' => $password]);
    $this->post('/two-factor-challenge', ['recovery_code' => $old[0]])->assertSessionHasErrors('recovery_code');
    $this->post('/two-factor-challenge', ['recovery_code' => $new[0]])->assertRedirect('/');
});

it('no se pueden generar códigos sin 2FA activo', function (): void {
    $user = User::factory()->create();

    $this->withSession(recentPassword())->actingAs($user)->post('/user/two-factor-authentication');
    $this->withSession(recentPassword())->actingAs($user)->post('/user/two-factor-recovery-codes')->assertSessionHasErrors('two_factor');

    expect($user->fresh()?->two_factor_recovery_codes)->toBeNull();
});

it('un usuario normal puede desactivar el 2FA y queda auditado', function (): void {
    $user = User::factory()->create();
    enrollTwoFactor($user);

    $this->withSession(recentPassword())->actingAs($user)->delete('/user/two-factor-authentication')->assertRedirect();

    $fresh = $user->fresh();
    expect($fresh?->two_factor_secret)->toBeNull()
        ->and($fresh?->two_factor_recovery_codes)->toBeNull()
        ->and($fresh?->two_factor_confirmed_at)->toBeNull()
        ->and(AuditLog::where('event', AuditEvent::TwoFactorDisabled->value)->count())->toBe(1);
});

it('un administrador de la plataforma sin 2FA solo llega a la pantalla de seguridad', function (): void {
    $admin = platformAdmin();

    $this->actingAs($admin)->get('/account')->assertRedirect(route('security'));

    $this->withSession(recentPassword())->actingAs($admin)->get('/settings/security')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('twoFactor.required', true)->where('twoFactor.enabled', false));
});

it('un administrador de la plataforma con 2FA activo entra y no puede desactivarlo', function (): void {
    $admin = platformAdmin();
    enrollTwoFactor($admin);

    $this->actingAs($admin)->get('/account')->assertOk();

    $this->withSession(recentPassword())->actingAs($admin)->delete('/user/two-factor-authentication')
        ->assertSessionHasErrors(['two_factor' => 'La verificación en dos pasos es obligatoria para administradores y no se puede desactivar.']);

    expect($admin->fresh()?->hasEnabledTwoFactorAuthentication())->toBeTrue();
});

it('un administrador puede cancelar una activación que aún no confirmó', function (): void {
    $admin = platformAdmin();
    app(EnableTwoFactorAuthentication::class)($admin);

    $this->withSession(recentPassword())->actingAs($admin)->delete('/user/two-factor-authentication')->assertSessionHasNoErrors();

    expect($admin->fresh()?->two_factor_secret)->toBeNull();
});

it('la cuenta avisa cuando quedan pocos códigos de recuperación', function (): void {
    $user = User::factory()->create();
    $codes = enrollTwoFactor($user);

    $this->actingAs($user)->get('/account')->assertInertia(fn (AssertableInertia $page) => $page->where('recoveryWarning', null));

    foreach (array_slice($codes, 0, 8) as $code) {
        RecoveryCodes::consume($user->fresh(), $code);
    }
    $user->refresh();

    $this->actingAs($user)->get('/account')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('recoveryWarning', 'Te quedan 2 códigos de recuperación. Genera unos nuevos desde Seguridad.'));
});

it('la pantalla de confirmar contraseña rechaza una contraseña incorrecta con un mensaje en español', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/user/confirm-password')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/ConfirmPassword'));

    $this->actingAs($user)->post('/user/confirm-password', ['password' => Str::random(24)])
        ->assertSessionHasErrors(['password' => 'La contraseña no es correcta.']);
});

it('la seguridad exige haber confirmado la contraseña hace poco', function (): void {
    $this->actingAs(User::factory()->create())->get('/settings/security')->assertRedirect(route('password.confirm'));
});

it('los códigos de recuperación se normalizan y comparan sin distinguir mayúsculas ni guiones', function (): void {
    $user = User::factory()->create();
    $codes = enrollTwoFactor($user);
    $fresh = $user->fresh();

    expect(RecoveryCodes::normalize('ab-cd 12.3'))->toBe('ABCD123')
        ->and(RecoveryCodes::matches($fresh, strtolower($codes[3])))->toBeTrue()
        ->and(RecoveryCodes::matches($fresh, str_replace('-', '', $codes[3])))->toBeTrue()
        ->and(RecoveryCodes::matches($fresh, 'AAAAAAAA-AAAAAAAA'))->toBeFalse()
        ->and(RecoveryCodes::matches($fresh, ''))->toBeFalse();
});

it('el 2FA no se activa por mensajes enviados a mano sin sesión', function (): void {
    $this->post('/user/two-factor-authentication')->assertRedirect('/login');
    $this->delete('/user/two-factor-authentication')->assertRedirect('/login');
    $this->post('/user/two-factor-recovery-codes')->assertRedirect('/login');
});

it('el verificador TOTP no deja repetir un código, ni siquiera el de la ventana siguiente', function (): void {
    $engine = new Google2FA;
    $secret = $engine->generateSecretKey(32);
    $provider = app(TwoFactorProvider::class);
    $step = intdiv(time(), 30);

    $next = $engine->oathTotp($secret, $step + 1);
    expect($provider->verify($secret, $next))->toBeTrue()
        ->and($provider->verify($secret, $next))->toBeFalse();

    // Un código anterior al último aceptado tampoco sirve, aunque siga dentro de la tolerancia.
    expect($provider->verify($secret, $engine->oathTotp($secret, $step)))->toBeFalse();
});

it('el verificador TOTP rechaza códigos fuera de la tolerancia o con formato incorrecto', function (): void {
    $engine = new Google2FA;
    $secret = $engine->generateSecretKey(32);
    $provider = app(TwoFactorProvider::class);
    $step = intdiv(time(), 30);

    expect($provider->verify($secret, $engine->oathTotp($secret, $step + 3)))->toBeFalse()
        ->and($provider->verify($secret, $engine->oathTotp($secret, $step - 3)))->toBeFalse()
        ->and($provider->verify($secret, '12345'))->toBeFalse()
        ->and($provider->verify($secret, 'abcdef'))->toBeFalse()
        ->and($provider->verify($secret, ''))->toBeFalse();
});

it('el verificador TOTP lleva la cuenta por secreto: el código de una cuenta no gasta el de otra', function (): void {
    $engine = new Google2FA;
    $first = $engine->generateSecretKey(32);
    $second = $engine->generateSecretKey(32);
    $provider = app(TwoFactorProvider::class);
    $step = intdiv(time(), 30);

    expect($provider->verify($first, $engine->oathTotp($first, $step)))->toBeTrue()
        ->and($provider->verify($second, $engine->oathTotp($second, $step)))->toBeTrue();
});
