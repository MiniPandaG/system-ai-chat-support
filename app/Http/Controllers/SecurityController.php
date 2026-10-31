<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\RecoveryCodes;
use App\Services\UserSessions;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Fortify;

class SecurityController extends Controller
{
    public function __invoke(Request $request, UserSessions $sessions): Response
    {
        /** @var User $user */
        $user = $request->user();

        $enabled = $user->hasEnabledTwoFactorAuthentication();
        $pending = $user->two_factor_secret !== null && ! $enabled;
        $secret = $pending ? Fortify::currentEncrypter()->decrypt((string) $user->two_factor_secret) : null;

        return Inertia::render('settings/Security', [
            'copy' => __('security'),
            'twoFactor' => [
                'enabled' => $enabled,
                'pending' => $pending,
                'required' => $user->is_platform_admin,
                'remainingCodes' => $enabled ? RecoveryCodes::remaining($user) : 0,
            ],
            // El QR y la clave solo existen mientras se activa el 2FA; después no se vuelven a entregar.
            'sessions' => $sessions->list($user, $request->session()->getId()),
            'setup' => $pending && is_string($secret) ? [
                'qr' => 'data:image/svg+xml;base64,'.base64_encode($user->twoFactorQrCodeSvg()),
                'key' => trim(chunk_split($secret, 4, ' ')),
            ] : null,
        ]);
    }
}
