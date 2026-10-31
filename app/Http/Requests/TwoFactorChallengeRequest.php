<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use App\Services\RecoveryCodes;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;

/**
 * Igual que el de Fortify, pero compara los códigos de recuperación contra sus huellas y no en claro.
 */
class TwoFactorChallengeRequest extends TwoFactorLoginRequest
{
    public function validRecoveryCode()
    {
        $input = $this->input('recovery_code');

        if (! is_string($input) || $input === '') {
            return null;
        }

        $user = $this->challengedUser();

        if (! $user instanceof User || ! RecoveryCodes::matches($user, $input)) {
            return null;
        }

        $this->session()->forget('login.id');

        return RecoveryCodes::normalize($input);
    }
}
