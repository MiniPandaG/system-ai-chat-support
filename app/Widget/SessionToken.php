<?php

declare(strict_types=1);

namespace App\Widget;

use Illuminate\Support\Str;

/**
 * El token que identifica al visitante. Lleva la empresa delante («7.AbC…») para poder fijarla antes de buscar la sesión
 * (el filtro de empresa exige conocerla); la parte secreta son 43 caracteres aleatorios y en la base solo se guarda su
 * huella, de modo que ni una copia de la base de datos permite hacerse pasar por un visitante.
 */
final class SessionToken
{
    private const SECRET_LENGTH = 43;

    /**
     * @return array{token: string, hash: string}
     */
    public static function issue(int $tenantId): array
    {
        $token = $tenantId.'.'.Str::random(self::SECRET_LENGTH);

        return ['token' => $token, 'hash' => self::hash($token)];
    }

    /**
     * La empresa y la huella de un token recibido; nulo si no tiene la forma exacta (así no llega nada raro a la consulta).
     *
     * @return array{tenant_id: int, hash: string}|null
     */
    public static function parse(?string $token): ?array
    {
        if ($token === null || preg_match('/\A([1-9]\d{0,17})\.([A-Za-z0-9]{'.self::SECRET_LENGTH.'})\z/', $token, $parts) !== 1) {
            return null;
        }

        return ['tenant_id' => (int) $parts[1], 'hash' => self::hash($token)];
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
