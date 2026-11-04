<?php

declare(strict_types=1);

namespace App\Csat;

use Illuminate\Support\Str;

/**
 * El token del enlace de la encuesta. Lleva la empresa delante («7.AbC…») para fijarla antes de buscar la encuesta (el
 * filtro de empresa exige conocerla); la parte secreta son 43 caracteres aleatorios (256 bits) y en la base solo se guarda
 * su huella: ni una copia de la base permite responder por un cliente.
 */
final class SurveyToken
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
     * La empresa y la huella de un token recibido; nulo si no tiene la forma exacta, así no llega nada raro a la consulta.
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
