<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;

function verificationUrl(User $user, int $minutes = 60): string
{
    return URL::temporarySignedRoute('verification.verify', now()->addMinutes($minutes), [
        'id' => $user->getKey(),
        'hash' => sha1($user->getEmailForVerification()),
    ]);
}

it('el usuario exige verificar el correo', function (): void {
    expect(new User)->toBeInstanceOf(MustVerifyEmail::class);
});

it('un usuario sin verificar no pasa a la cuenta y va a la pantalla de verificación', function (): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get('/account')->assertRedirect(route('verification.notice'));
});

it('un usuario verificado entra a la cuenta', function (): void {
    $this->actingAs(User::factory()->create())->get('/account')->assertOk();
});

it('muestra la pantalla de verificación en español', function (): void {
    $this->actingAs(User::factory()->unverified()->create())
        ->get('/email/verify')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/VerifyEmail')
            ->where('copy.title', 'Verifica tu correo'));
});

it('reenvía el enlace y avisa', function (): void {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->post('/email/verification-notification')
        ->assertSessionHas('status', 'verification-link-sent');

    Notification::assertSentTo($user, VerifyEmailNotification::class);
});

it('limita los reenvíos a tres cada diez minutos', function (): void {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    foreach (range(1, 3) as $_) {
        $this->actingAs($user)->post('/email/verification-notification')->assertSessionHasNoErrors();
    }

    $this->actingAs($user)->post('/email/verification-notification')->assertSessionHasErrors('email');
    Notification::assertSentTimes(VerifyEmailNotification::class, 3);
});

it('el enlace firmado verifica el correo y queda auditado', function (): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(verificationUrl($user))->assertRedirect();

    expect($user->fresh()?->hasVerifiedEmail())->toBeTrue()
        ->and(AuditLog::where('event', AuditEvent::EmailVerified->value)->where('user_id', $user->id)->count())->toBe(1);
});

it('rechaza un enlace con el hash alterado, caducado o de otra cuenta', function (): void {
    $user = User::factory()->unverified()->create();
    $other = User::factory()->unverified()->create();

    $tampered = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->getKey(), 'hash' => sha1('otro@example.com')]);
    $this->actingAs($user)->get($tampered)->assertForbidden();

    $expired = verificationUrl($user, 60);
    $this->travel(61)->minutes();
    $this->actingAs($user)->get($expired)->assertForbidden();

    $this->actingAs($user)->get(verificationUrl($other))->assertForbidden();

    expect($user->fresh()?->hasVerifiedEmail())->toBeFalse()
        ->and($other->fresh()?->hasVerifiedEmail())->toBeFalse();
});

it('rechaza un enlace sin firma', function (): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('verification.verify', ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())], false))
        ->assertForbidden();
});

it('el correo de verificación está en español y sale por cola', function (): void {
    $user = User::factory()->unverified()->create(['name' => 'Ada Lovelace']);
    $notification = new VerifyEmailNotification;
    $mail = $notification->toMail($user);

    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($mail->subject)->toBe('Verifica tu correo en GeckoChat')
        ->and($mail->actionText)->toBe('Verificar correo')
        ->and($mail->actionUrl)->toContain('/email/verify/'.$user->getKey().'/')
        ->and($mail->actionUrl)->toContain('signature=');
});
