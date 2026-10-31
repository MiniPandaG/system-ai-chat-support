<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\AuditEvent;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;

class RecordAuthenticationEvents
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handleLogin(Login $event): void
    {
        $this->audit->record(AuditEvent::LoginSucceeded, $this->userId($event->user));
    }

    public function handleFailed(Failed $event): void
    {
        $email = $event->credentials['email'] ?? null;

        // Fortify dispara Failed sin usuario; se busca por correo para ligar el intento a la cuenta en la auditoría.
        $userId = $event->user !== null
            ? $this->userId($event->user)
            : (is_string($email) ? User::query()->where('email', mb_strtolower($email))->value('id') : null);

        $this->audit->record(
            AuditEvent::LoginFailed,
            is_int($userId) ? $userId : null,
            is_string($email) ? ['email_fingerprint' => AuditLogger::fingerprint($email)] : [],
        );
    }

    public function handleLockout(Lockout $event): void
    {
        $email = $event->request->input('email');

        $this->audit->record(
            AuditEvent::LoginLockedOut,
            null,
            is_string($email) ? ['email_fingerprint' => AuditLogger::fingerprint($email)] : [],
        );
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        $this->audit->record(AuditEvent::PasswordResetCompleted, $this->userId($event->user));
    }

    public function handleVerified(Verified $event): void
    {
        if ($event->user instanceof Authenticatable) {
            $this->audit->record(AuditEvent::EmailVerified, $this->userId($event->user));
        }
    }

    public function handleTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->audit->record(AuditEvent::TwoFactorConfirmed, $this->userId($event->user));
    }

    public function handleTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->audit->record(AuditEvent::TwoFactorDisabled, $this->userId($event->user));
    }

    public function handleTwoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        $this->audit->record(AuditEvent::TwoFactorFailed, $this->userId($event->user));
    }

    public function handleRecoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        $this->audit->record(AuditEvent::RecoveryCodesGenerated, $this->userId($event->user));
    }

    public function handleRecoveryCodeReplaced(RecoveryCodeReplaced $event): void
    {
        // El código usado no se guarda, ni siquiera su huella: solo que se gastó uno.
        $this->audit->record(AuditEvent::RecoveryCodeUsed, $this->userId($event->user));
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->user !== null) {
            $this->audit->record(AuditEvent::Logout, $this->userId($event->user));
        }
    }

    private function userId(Authenticatable $user): int
    {
        $id = $user->getAuthIdentifier();

        return is_int($id) ? $id : (int) (is_string($id) ? $id : 0);
    }
}
