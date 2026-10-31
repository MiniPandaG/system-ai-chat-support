<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Services\AuditLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

function strongPassword(): string
{
    return 'Nueva-'.Str::random(12).'8';
}

it('muestra la pantalla de recuperación en español', function (): void {
    $this->get('/forgot-password')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/ForgotPassword')
            ->where('copy.title', 'Recuperar contraseña'));
});

it('envía el enlace a una cuenta existente', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email])
        ->assertSessionHas('status', 'Si existe una cuenta con ese correo, enviamos un enlace. Revisa tu bandeja de entrada.')
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($user, ResetPasswordNotification::class);
});

it('responde exactamente igual si la cuenta no existe y no envía nada', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    $existing = $this->post('/forgot-password', ['email' => $user->email]);
    $missing = $this->post('/forgot-password', ['email' => 'nadie-'.Str::random(6).'@example.com']);

    expect($missing->getStatusCode())->toBe($existing->getStatusCode())
        ->and($missing->headers->get('Location'))->toBe($existing->headers->get('Location'))
        ->and(session('status'))->toBe(__('passwords.sent'));

    $missing->assertSessionHasNoErrors();
    Notification::assertSentTimes(ResetPasswordNotification::class, 1);
});

it('los correos de cuenta salen por cola, para que la respuesta no tarde distinto', function (): void {
    expect(new ResetPasswordNotification('token'))->toBeInstanceOf(ShouldQueue::class);
});

it('el correo está en español y el enlace es de un solo uso con caducidad de 30 minutos', function (): void {
    $user = User::factory()->create(['name' => 'Ada Lovelace']);
    $mail = (new ResetPasswordNotification('el-token'))->toMail($user);

    expect($mail->subject)->toBe('Restablece tu contraseña de TikaroChat')
        ->and($mail->greeting)->toBe('Hola, Ada Lovelace.')
        ->and($mail->actionUrl)->toContain('/reset-password/el-token')
        ->and($mail->actionUrl)->toContain('email='.urlencode($user->email))
        ->and(implode(' ', $mail->outroLines))->toContain('caduca en 30 minutos')
        ->and(config('auth.passwords.users.expire'))->toBe(30);
});

it('limita a tres solicitudes por correo cada diez minutos, exista o no la cuenta', function (): void {
    Notification::fake();
    $email = 'objetivo-'.Str::random(6).'@example.com';

    foreach (range(1, 3) as $_) {
        $this->post('/forgot-password', ['email' => $email])->assertSessionHasNoErrors();
    }

    $this->post('/forgot-password', ['email' => $email])->assertSessionHasErrors('email');
    expect(session('errors')?->first('email'))->toStartWith('Demasiados intentos');

    $this->travel(11)->minutes();
    $this->post('/forgot-password', ['email' => $email])->assertSessionHasNoErrors();
});

it('restablece la contraseña con Argon2id, cierra las sesiones abiertas y el enlace es de un solo uso', function (): void {
    $user = User::factory()->create();
    $token = Password::createToken($user);
    $new = strongPassword();

    DB::table('sessions')->insert([
        'id' => Str::random(40),
        'user_id' => $user->id,
        'payload' => 'x',
        'last_activity' => time(),
    ]);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => $new,
        'password_confirmation' => $new,
    ])->assertRedirect('/login')->assertSessionHas('status', 'Tu contraseña se actualizó. Inicia sesión con la nueva.');

    $stored = $user->fresh()?->password;
    expect(Hash::check($new, (string) $stored))->toBeTrue()
        ->and($stored)->toStartWith('$argon2id$')
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);

    $again = strongPassword();
    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => $again,
        'password_confirmation' => $again,
    ])->assertSessionHasErrors(['email' => 'El enlace de recuperación no es válido o ya caducó. Solicita uno nuevo.']);

    expect(Hash::check($again, (string) $user->fresh()?->password))->toBeFalse();
});

it('un enlace vencido no sirve', function (): void {
    $user = User::factory()->create();
    $token = Password::createToken($user);
    $new = strongPassword();

    $this->travel(31)->minutes();

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => $new,
        'password_confirmation' => $new,
    ])->assertSessionHasErrors(['email' => 'El enlace de recuperación no es válido o ya caducó. Solicita uno nuevo.']);
});

it('da el mismo mensaje con un enlace falso que con un correo inexistente', function (): void {
    $user = User::factory()->create();
    $new = strongPassword();

    $this->post('/reset-password', ['token' => 'falso', 'email' => $user->email, 'password' => $new, 'password_confirmation' => $new])
        ->assertSessionHasErrors('email');
    $forExisting = session('errors')?->first('email');

    $this->post('/reset-password', ['token' => 'falso', 'email' => 'nadie@example.com', 'password' => $new, 'password_confirmation' => $new])
        ->assertSessionHasErrors('email');
    $forMissing = session('errors')?->first('email');

    expect($forExisting)->toBe($forMissing)->not->toBeNull();
});

it('rechaza contraseñas débiles con mensajes en español y no cambia nada', function (): void {
    $user = User::factory()->create();
    $original = $user->password;

    $cases = [
        'Corta1' => 'al menos 10 caracteres',
        'todominusculas123' => 'una mayúscula',
        'SinNumeroAlguno' => 'un número',
    ];

    foreach ($cases as $weak => $expected) {
        $token = Password::createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => $weak,
            'password_confirmation' => $weak,
        ])->assertSessionHasErrors('password');

        expect(session('errors')?->first('password'))->toContain($expected);
    }

    expect($user->fresh()?->password)->toBe($original);
});

it('rechaza una confirmación que no coincide', function (): void {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => strongPassword(),
        'password_confirmation' => strongPassword(),
    ])->assertSessionHasErrors(['password' => 'Las contraseñas no coinciden.']);
});

it('audita la solicitud y el cambio sin guardar tokens, contraseñas ni correos', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    $token = Password::createToken($user);
    $new = strongPassword();
    $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => $new, 'password_confirmation' => $new]);

    $requested = AuditLog::where('event', AuditEvent::PasswordResetRequested->value)->firstOrFail();
    expect($requested->user_id)->toBe($user->id)
        ->and($requested->context)->toEqualCanonicalizing(['email_fingerprint' => AuditLogger::fingerprint($user->email), 'result' => 'passwords.sent'])
        ->and(AuditLog::where('event', AuditEvent::PasswordResetCompleted->value)->where('user_id', $user->id)->count())->toBe(1);

    $everything = AuditLog::query()->get()->toJson();
    expect($everything)->not->toContain($user->email)->not->toContain($token)->not->toContain($new);
});

it('la pantalla de nueva contraseña recibe el token y el correo del enlace', function (): void {
    $this->get('/reset-password/el-token?email=ada%40example.com')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/ResetPassword')
            ->where('token', 'el-token')
            ->where('email', 'ada@example.com')
            ->where('copy.requirements', 'Mínimo 10 caracteres, una mayúscula y un número.'));
});
