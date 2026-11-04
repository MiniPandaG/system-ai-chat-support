<?php

declare(strict_types=1);

namespace App\Ai;

/** Por dónde llegó el mensaje del cliente al que la IA puede responder. */
enum AiChannel: string
{
    case Widget = 'widget';
    case Email = 'email';
}
