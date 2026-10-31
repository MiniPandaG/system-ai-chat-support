<?php

declare(strict_types=1);

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use App\Services\RecoveryCodes;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * is_platform_admin no es rellenable: solo lo fija el comando platform:create-admin.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    /**
     * Los códigos de recuperación se guardan con huella y no se pueden recuperar en claro.
     *
     * @return list<string>
     */
    public function recoveryCodes(): array
    {
        return [];
    }

    /**
     * @param  string  $code
     */
    public function replaceRecoveryCode($code): void
    {
        if (RecoveryCodes::consume($this, $code)) {
            RecoveryCodeReplaced::dispatch($this, $code);
        }
    }

    // No hay «recordarme»: sin columna remember_token, estos métodos evitan leer un atributo inexistente.
    public function getRememberToken(): ?string
    {
        return null;
    }

    /**
     * @param  string|null  $value
     */
    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return '';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
        ];
    }
}
