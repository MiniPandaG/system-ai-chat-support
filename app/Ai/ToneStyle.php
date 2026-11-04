<?php

declare(strict_types=1);

namespace App\Ai;

/** El tono con el que la IA le habla a los clientes de la empresa. */
enum ToneStyle: string
{
    case Formal = 'formal';
    case Friendly = 'friendly';
    case Custom = 'custom';
}
