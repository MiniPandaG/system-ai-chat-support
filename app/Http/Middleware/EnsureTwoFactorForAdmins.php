<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un administrador (de la plataforma o de una empresa) sin 2FA activo solo puede llegar a la pantalla de seguridad
 * para activarlo (RS-03).
 */
class EnsureTwoFactorForAdmins
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->requiresTwoFactor() && ! $user->hasEnabledTwoFactorAuthentication()) {
            return redirect()->route('security');
        }

        return $next($request);
    }
}
