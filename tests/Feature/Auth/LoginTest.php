<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

it('muestra la pantalla de inicio de sesión en español', function (): void {
    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/Login')
            ->where('copy.title', 'Iniciar sesión')
            ->where('copy.submit', 'Iniciar sesión'));
});

it('inicia sesión con credenciales correctas y regenera la sesión', function (): void {
    $password = Str::random(24);
    $user = User::factory()->password($password)->create();

    $this->get('/login');
    $before = session()->getId();

    $this->post('/login', ['email' => $user->email, 'password' => $password])
        ->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
    expect(session()->getId())->not->toBe($before);
});

it('actualiza al iniciar sesión un hash Argon2id hecho con parámetros más débiles', function (): void {
    $password = Str::random(24);
    $weak = Hash::driver('argon2id')->make($password, ['memory' => 512, 'time' => 1]);
    $user = User::factory()->password($weak)->create();

    expect(Hash::needsRehash($user->password))->toBeTrue();

    $this->post('/login', ['email' => $user->email, 'password' => $password])->assertRedirect('/');

    $updated = $user->fresh()?->password;
    expect($updated)->toStartWith('$argon2id$')->not->toBe($weak)
        ->and(Hash::needsRehash((string) $updated))->toBeFalse();
});

it('rechaza credenciales incorrectas con el mismo mensaje exista o no la cuenta', function (): void {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => Str::random(24)])
        ->assertSessionHasErrors(['email' => 'El correo o la contraseña no son correctos.']);
    $existing = session('errors')?->getBag('default')->all();

    $this->post('/login', ['email' => 'no-existe-'.Str::random(6).'@example.com', 'password' => Str::random(24)])
        ->assertSessionHasErrors(['email' => 'El correo o la contraseña no son correctos.']);
    $missing = session('errors')?->getBag('default')->all();

    expect($existing)->toBe($missing);
    $this->assertGuest();
});

it('calcula un hash aunque la cuenta no exista, para que el tiempo no la delate', function (): void {
    expect(Cache::has('auth.dummy-password-hash'))->toBeFalse();

    $this->post('/login', ['email' => 'nadie-'.Str::random(6).'@example.com', 'password' => Str::random(24)])
        ->assertSessionHasErrors('email');

    expect(Cache::get('auth.dummy-password-hash'))->toStartWith('$argon2id$');
});

it('bloquea el acceso tras cinco intentos fallidos y lo libera a los diez minutos', function (): void {
    $password = Str::random(24);
    $user = User::factory()->password($password)->create();

    foreach (range(1, 5) as $_) {
        $this->post('/login', ['email' => $user->email, 'password' => Str::random(24)]);
    }

    $this->post('/login', ['email' => $user->email, 'password' => $password])
        ->assertSessionHasErrors('email');

    expect(session('errors')?->first('email'))->toStartWith('Demasiados intentos. Vuelve a intentarlo en ')
        ->and(session('errors')?->first('email'))->toContain('minutos');
    $this->assertGuest();

    $this->travel(11)->minutes();

    $this->post('/login', ['email' => $user->email, 'password' => $password])->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
});

it('el bloqueo de una cuenta no afecta a otra', function (): void {
    $victim = User::factory()->create();
    $password = Str::random(24);
    $other = User::factory()->password($password)->create();

    foreach (range(1, 6) as $_) {
        $this->post('/login', ['email' => $victim->email, 'password' => Str::random(24)]);
    }

    $this->post('/login', ['email' => $other->email, 'password' => $password])->assertRedirect('/');
});

it('cierra la sesión y la invalida', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/logout')->assertRedirect('/');

    $this->assertGuest();
});

it('redirige a los invitados al inicio de sesión y a los autenticados a su cuenta', function (): void {
    $this->get('/account')->assertRedirect('/login');
    $this->get('/')->assertRedirect('/account');

    $this->actingAs(User::factory()->create())->get('/login')->assertRedirect('/');
});

it('registra cada evento de autenticación una sola vez y sin datos sensibles', function (): void {
    $password = Str::random(24);
    $user = User::factory()->password($password)->create();

    $this->post('/login', ['email' => $user->email, 'password' => Str::random(24)]);
    $this->post('/login', ['email' => $user->email, 'password' => $password]);
    $this->post('/logout');

    expect(AuditLog::where('event', AuditEvent::LoginFailed->value)->count())->toBe(1)
        ->and(AuditLog::where('event', AuditEvent::LoginSucceeded->value)->count())->toBe(1)
        ->and(AuditLog::where('event', AuditEvent::Logout->value)->count())->toBe(1);

    $failed = AuditLog::where('event', AuditEvent::LoginFailed->value)->firstOrFail();
    expect($failed->user_id)->toBe($user->id)
        ->and($failed->context)->toBe(['email_fingerprint' => AuditLogger::fingerprint($user->email)]);

    $everything = AuditLog::query()->get()->toJson();
    expect($everything)->not->toContain($user->email)->not->toContain($password);
});

it('registra el bloqueo con una huella del correo y no con el correo', function (): void {
    $email = 'objetivo-'.Str::random(6).'@example.com';

    foreach (range(1, 6) as $_) {
        $this->post('/login', ['email' => $email, 'password' => Str::random(24)]);
    }

    $locked = AuditLog::where('event', AuditEvent::LoginLockedOut->value)->firstOrFail();

    expect($locked->context)->toBe(['email_fingerprint' => AuditLogger::fingerprint($email)])
        ->and(AuditLog::query()->get()->toJson())->not->toContain($email);
});

it('la auditoría rechaza guardar contraseñas, tokens o correos en claro', function (): void {
    $audit = app(AuditLogger::class);

    foreach (['password', 'token', 'recovery_code', 'email', 'secret'] as $key) {
        expect(fn () => $audit->record(AuditEvent::LoginFailed, null, [$key => 'valor']))
            ->toThrow(InvalidArgumentException::class);
    }
});
