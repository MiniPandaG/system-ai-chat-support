<?php

declare(strict_types=1);

namespace App\Tickets;

/**
 * Por dónde llegó un ticket. Hoy solo existe el panel; el chat web (M7), el correo (M8) y la API (M9) añaden su caso.
 */
enum Channel: string
{
    case Panel = 'panel';
}
