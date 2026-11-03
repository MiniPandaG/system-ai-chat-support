<?php

declare(strict_types=1);

namespace App\Api;

/**
 * Qué puede hacer una llave. Cada nivel incluye el anterior: leer, escribir (crear tickets, clientes y respuestas) y
 * administrar (cambiar el estado, la prioridad y quien atiende un ticket).
 */
enum ApiPermission: string
{
    case Read = 'read';
    case Write = 'write';
    case Admin = 'admin';

    public function rank(): int
    {
        return match ($this) {
            self::Read => 1,
            self::Write => 2,
            self::Admin => 3,
        };
    }

    /** Esta llave alcanza para lo que pide la ruta. */
    public function allows(self $needed): bool
    {
        return $this->rank() >= $needed->rank();
    }
}
