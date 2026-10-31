<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Http\Request;
use Laravel\Fortify\LoginRateLimiter;

/**
 * Cinco intentos fallidos por correo e IP bloquean el inicio de sesión durante diez minutos.
 */
class LoginThrottle extends LoginRateLimiter
{
    public function tooManyAttempts(Request $request): bool
    {
        return $this->limiter->tooManyAttempts($this->throttleKey($request), 5);
    }

    public function increment(Request $request): void
    {
        $this->limiter->hit($this->throttleKey($request), 600);
    }
}
