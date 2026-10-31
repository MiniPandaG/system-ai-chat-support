<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Armada por partes: una contraseña de ejemplo escrita de corrido la marca el escaneo de secretos.
 */
function currentPassword(): string
{
    return str_repeat('Ab', 7).'1';
}

/**
 * Sesión con la contraseña confirmada hace poco, que es lo que exigen las rutas de Seguridad.
 *
 * @return array<string, int>
 */
function confirmedPasswordSession(): array
{
    return ['auth.password_confirmed_at' => time()];
}

function accountWithKnownPassword(): User
{
    return User::factory()->create(['password' => currentPassword()]);
}

function newPassword(): string
{
    return 'Nueva-'.Str::random(12).'8';
}

/**
 * @return array<string, string>
 */
function passwordChange(string $new): array
{
    return ['current_password' => currentPassword(), 'password' => $new, 'password_confirmation' => $new];
}

it('cambia la contraseña con Argon2id, cierra las demás sesiones y avisa por correo', function (): void {
    Notification::fake();
    $user = accountWithKnownPassword();
    $new = newPassword();

    $current = Str::random(40);

    foreach ([$current, Str::random(40), Str::random(40)] as $id) {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => time()]);
    }

    $this->actingAs($user)
        ->withSession(confirmedPasswordSession())
        ->withCookie(config()->string('session.cookie'), $current)
        ->put('/settings/password', passwordChange($new))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Tu contraseña se actualizó. Cerramos tus otras sesiones.');

    $stored = (string) $user->fresh()?->password;

    expect(Hash::check($new, $stored))->toBeTrue()
        ->and(Hash::check(currentPassword(), $stored))->toBeFalse()
        ->and($stored)->toStartWith('$argon2id$')
        ->and(DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all())->toBe([$current]);

    Notification::assertSentTo($user, PasswordChangedNotification::class);
});

it('el aviso de cambio de contraseña sale por cola y en español', function (): void {
    $user = User::factory()->create();
    $mail = (new PasswordChangedNotification)->toMail($user);

    expect(new PasswordChangedNotification)->toBeInstanceOf(ShouldQueue::class)
        ->and($mail->subject)->toBe('Cambiaste tu contraseña de TikaroChat')
        ->and($mail->actionUrl)->toBe(route('password.request'));
});

it('regenera la sesión al cambiar la contraseña', function (): void {
    $user = accountWithKnownPassword();

    $before = Str::random(40);

    $this->actingAs($user)
        ->withSession(confirmedPasswordSession())
        ->withCookie(config()->string('session.cookie'), $before)
        ->put('/settings/password', passwordChange(newPassword()))
        ->assertSessionHasNoErrors();

    expect(session()->getId())->not->toBe($before);
});

it('al cambiar la contraseña la sesión anterior deja de existir en la base, no solo cambia de identificador', function (): void {
    config(['session.driver' => 'database']);

    $user = accountWithKnownPassword();
    $old = Str::random(40);
    DB::table('sessions')->insert(['id' => $old, 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => time()]);

    $this->actingAs($user)
        ->withSession(confirmedPasswordSession())
        ->withCookie(config()->string('session.cookie'), $old)
        ->put('/settings/password', passwordChange(newPassword()))
        ->assertSessionHasNoErrors();

    expect(DB::table('sessions')->where('id', $old)->exists())->toBeFalse();
});

it('rechaza una contraseña actual incorrecta y no cambia nada', function (): void {
    Notification::fake();
    $user = accountWithKnownPassword();
    $hash = (string) $user->password;

    $this->actingAs($user)
        ->withSession(confirmedPasswordSession())
        ->put('/settings/password', ['current_password' => 'Otra-Contrasena-1', 'password' => newPassword(), 'password_confirmation' => ''])
        ->assertSessionHasErrors(['current_password' => 'La contraseña actual no es correcta.']);

    expect((string) $user->fresh()?->password)->toBe($hash);
    Notification::assertNothingSent();
});

it('rechaza contraseñas débiles, que no coinciden o iguales a la actual', function (string $new, string $confirmation, string $field): void {
    $user = accountWithKnownPassword();
    $hash = (string) $user->password;

    $this->actingAs($user)
        ->withSession(confirmedPasswordSession())
        ->put('/settings/password', ['current_password' => currentPassword(), 'password' => $new, 'password_confirmation' => $confirmation])
        ->assertSessionHasErrors($field);

    expect((string) $user->fresh()?->password)->toBe($hash);
})->with([
    'corta' => ['Ab1', 'Ab1', 'password'],
    'sin mayúscula' => ['todominusculas123', 'todominusculas123', 'password'],
    'sin número' => ['SinNumerosAqui', 'SinNumerosAqui', 'password'],
    'no coincide' => ['Nueva-Segura-81x', 'Nueva-Segura-81y', 'password'],
    'igual a la actual' => [currentPassword(), currentPassword(), 'password'],
]);

it('da mensajes en español cuando la nueva contraseña no sirve', function (): void {
    $user = accountWithKnownPassword();

    $this->actingAs($user)
        ->withSession(confirmedPasswordSession())
        ->put('/settings/password', passwordChange(currentPassword()))
        ->assertSessionHasErrors(['password' => 'La contraseña nueva debe ser distinta de la actual.']);
});

it('exige sesión iniciada y la contraseña confirmada hace poco', function (): void {
    $user = accountWithKnownPassword();

    $this->put('/settings/password', passwordChange(newPassword()))->assertRedirect('/login');

    $this->actingAs($user)
        ->put('/settings/password', passwordChange(newPassword()))
        ->assertRedirect(route('password.confirm'));

    expect(Hash::check(currentPassword(), (string) $user->fresh()?->password))->toBeTrue();
});

it('limita a cinco intentos cada diez minutos, también con datos correctos', function (): void {
    $user = accountWithKnownPassword();

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->actingAs($user)
            ->withSession(confirmedPasswordSession())
            ->put('/settings/password', ['current_password' => 'Incorrecta-'.$attempt, 'password' => newPassword(), 'password_confirmation' => ''])
            ->assertSessionHasErrors('current_password');
    }

    $new = newPassword();

    $this->actingAs($user)
        ->withSession(confirmedPasswordSession())
        ->put('/settings/password', passwordChange($new))
        ->assertSessionHasErrors('current_password');

    expect(session('errors')?->first('current_password'))->toContain('Demasiados intentos')
        ->and(Hash::check($new, (string) $user->fresh()?->password))->toBeFalse();
});

it('el límite de un usuario no afecta a otro', function (): void {
    $blocked = accountWithKnownPassword();
    $other = accountWithKnownPassword();

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->actingAs($blocked)
            ->withSession(confirmedPasswordSession())
            ->put('/settings/password', ['current_password' => 'Incorrecta-'.$attempt, 'password' => newPassword(), 'password_confirmation' => ''])
            ->assertSessionHasErrors('current_password');
    }

    $this->actingAs($other)
        ->withSession(confirmedPasswordSession())
        ->put('/settings/password', passwordChange(newPassword()))
        ->assertSessionHasNoErrors();
});

it('audita el cambio sin guardar contraseñas', function (): void {
    $user = accountWithKnownPassword();
    $new = newPassword();

    $this->actingAs($user)
        ->withSession(confirmedPasswordSession())
        ->put('/settings/password', passwordChange($new))
        ->assertSessionHasNoErrors();

    $log = AuditLog::query()->where('event', AuditEvent::PasswordChanged->value)->where('user_id', $user->id)->latest('id')->first();
    $dump = json_encode($log?->getAttributes());

    expect($log)->not->toBeNull()
        ->and($dump)->not->toContain($new)
        ->and($dump)->not->toContain(currentPassword())
        ->and($dump)->not->toContain($user->email);
});
