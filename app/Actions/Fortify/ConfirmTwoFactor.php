<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\RecoveryCodes;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;

/**
 * Al confirmar el primer código TOTP se emiten los códigos de recuperación; se muestran una vez (flash).
 */
class ConfirmTwoFactor extends ConfirmTwoFactorAuthentication
{
    public function __invoke($user, $code)
    {
        parent::__invoke($user, $code);

        if ($user instanceof User) {
            session()->flash('recovery_codes', RecoveryCodes::issue($user));
        }
    }
}
