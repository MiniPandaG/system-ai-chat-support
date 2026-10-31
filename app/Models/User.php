<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Role;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use App\Services\RecoveryCodes;
use Carbon\CarbonInterface as Carbon;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * is_platform_admin, tenant_id y role no son rellenables: el primero lo fija el comando platform:create-admin y los
 * otros dos el registro de empresa o la aceptación de una invitación.
 *
 * User no usa el filtro automático de empresa (BelongsToTenant): se consulta al iniciar sesión, antes de que exista
 * una empresa en contexto. Las consultas de equipo filtran por tenant_id de forma explícita.
 *
 * @property int|null $tenant_id
 * @property Role|null $role
 * @property Carbon|null $disabled_at
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

    /**
     * Los administradores, de la plataforma o de una empresa, deben proteger su cuenta con 2FA (RS-03).
     */
    public function requiresTwoFactor(): bool
    {
        return $this->is_platform_admin || $this->role === Role::Admin;
    }

    /**
     * Miembros de una empresa. User no lleva el filtro automático, así que cada consulta de equipo lo pide a mano.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeInTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
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
            'role' => Role::class,
            'disabled_at' => 'datetime',
        ];
    }
}
