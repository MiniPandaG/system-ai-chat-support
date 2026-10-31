<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\RecoveryCodes;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Account', [
            'copy' => __('auth.account'),
            'name' => $user->name,
            'recoveryWarning' => $this->recoveryWarning($user),
        ]);
    }

    private function recoveryWarning(User $user): ?string
    {
        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return null;
        }

        $left = RecoveryCodes::remaining($user);

        $message = match (true) {
            $left === 0 => __('security.two_factor.codes_none'),
            $left === 1 => __('security.two_factor.codes_low_one'),
            $left < 3 => __('security.two_factor.codes_low', ['count' => $left]),
            default => null,
        };

        return is_string($message) ? $message : null;
    }
}
