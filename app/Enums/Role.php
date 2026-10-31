<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Rol de un usuario dentro de su empresa. El personal de la plataforma no tiene rol de empresa.
 */
enum Role: string
{
    case Admin = 'admin';
    case Agent = 'agent';
    case Observer = 'observer';
}
