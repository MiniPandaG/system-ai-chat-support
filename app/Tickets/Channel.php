<?php

declare(strict_types=1);

namespace App\Tickets;

/**
 * Por dónde llegó un ticket. El correo llega por el hilo del ticket, no abre uno.
 */
enum Channel: string
{
    case Panel = 'panel';
    case Widget = 'widget';
    case Api = 'api';
}
