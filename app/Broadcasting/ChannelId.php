<?php

declare(strict_types=1);

namespace App\Broadcasting;

/**
 * Los identificadores que llegan en el nombre de un canal los escribe el navegador: antes de usarlos se comprueba que
 * sean números enteros positivos de tamaño razonable, ni más ni menos.
 */
final class ChannelId
{
    public static function isId(string $value): bool
    {
        return ctype_digit($value) && strlen($value) <= 18 && (int) $value > 0;
    }

    /** El identificador del canal es un identificador válido y coincide con el esperado (el de la empresa del usuario). */
    public static function matches(string $value, ?int $expected): bool
    {
        return $expected !== null && self::isId($value) && (int) $value === $expected;
    }
}
