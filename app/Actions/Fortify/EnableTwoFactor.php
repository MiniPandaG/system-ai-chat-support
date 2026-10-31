<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

/**
 * Genera solo el secreto TOTP (de 160 bits). Los códigos de recuperación se emiten al confirmar el primer código.
 */
class EnableTwoFactor extends EnableTwoFactorAuthentication
{
    /**
     * @param  mixed  $user
     * @param  bool  $force
     */
    public function __invoke($user, $force = false)
    {
        if (! $user instanceof User) {
            return;
        }

        if (empty($user->two_factor_secret) || $force === true) {
            $user->forceFill([
                'two_factor_secret' => Fortify::currentEncrypter()->encrypt(app(Google2FA::class)->generateSecretKey(32)),
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            TwoFactorAuthenticationEnabled::dispatch($user);
        }
    }
}
