<?php

declare(strict_types=1);

namespace App\Tickets;

/**
 * Tonos que puede tener una etiqueta. Es una lista cerrada de tokens semánticos del diseño: la empresa elige uno,
 * nunca escribe un color, así que no hay nada que validar por contraste ni CSS que filtrar.
 */
enum TagTone: string
{
    case Neutral = 'neutral';
    case Primary = 'primary';
    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Danger = 'danger';
}
