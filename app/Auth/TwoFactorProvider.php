<?php

declare(strict_types=1);

namespace App\Auth;

use Laravel\Fortify\TwoFactorAuthenticationProvider;

/**
 * Verificación TOTP que no admite repetir un código (RFC 6238, sección 5.2): se recuerda el último paso de tiempo
 * aceptado de cada secreto y solo se aceptan pasos posteriores. La de Fortify guarda el paso actual y no el que
 * coincidió, y deja repetir el código de la ventana siguiente.
 */
class TwoFactorProvider extends TwoFactorAuthenticationProvider
{
    private const STEP_SECONDS = 30;

    private const TOLERANCE_STEPS = 1;

    public function verify($secret, $code): bool
    {
        if (! is_string($secret) || ! is_string($code) || preg_match('/^\d{6}$/', $code) !== 1 || $this->cache === null) {
            return false;
        }

        $key = 'two-factor:last-step:'.hash('sha256', $secret);
        $lastAccepted = $this->cache->get($key);
        $current = intdiv(time(), self::STEP_SECONDS);

        for ($step = $current - self::TOLERANCE_STEPS; $step <= $current + self::TOLERANCE_STEPS; $step++) {
            if (hash_equals($this->engine->oathTotp($secret, $step), $code)) {
                if (is_int($lastAccepted) && $step <= $lastAccepted) {
                    return false;
                }

                $this->cache->put($key, $step, 300);

                return true;
            }
        }

        return false;
    }
}
