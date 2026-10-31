<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\RecoveryCodes;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Events\RecoveryCodesGenerated;

class GenerateRecoveryCodes extends GenerateNewRecoveryCodes
{
    public function __invoke($user)
    {
        if (! $user instanceof User || ! $user->hasEnabledTwoFactorAuthentication()) {
            throw ValidationException::withMessages([
                'two_factor' => [__('security.two_factor.not_enabled')],
            ]);
        }

        session()->flash('recovery_codes', RecoveryCodes::issue($user));

        RecoveryCodesGenerated::dispatch($user);
    }
}
