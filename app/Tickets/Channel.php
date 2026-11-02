<?php

declare(strict_types=1);

namespace App\Tickets;

/**
 * Por dónde llegó un ticket. El correo (M8) y la API (M9) añaden su caso.
 */
enum Channel: string
{
    case Panel = 'panel';
    case Widget = 'widget';
}
