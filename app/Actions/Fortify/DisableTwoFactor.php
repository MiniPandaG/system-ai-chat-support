<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;

class DisableTwoFactor extends DisableTwoFactorAuthentication
{
    public function __invoke($user)
    {
        if ($user instanceof User && $user->requiresTwoFactor() && $user->hasEnabledTwoFactorAuthentication()) {
            throw ValidationException::withMessages([
                'two_factor' => [__('security.two_factor.required_for_admin')],
            ]);
        }

        parent::__invoke($user);
    }
}
