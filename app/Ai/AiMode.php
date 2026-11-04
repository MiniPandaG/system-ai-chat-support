<?php

declare(strict_types=1);

namespace App\Ai;

/** Qué hace la IA en un canal. */
enum AiMode: string
{
    /** No participa. */
    case Off = 'off';

    /** Prepara un borrador que una persona aprueba, edita o descarta antes de que llegue al cliente. */
    case Shadow = 'shadow';

    /** Responde sola cuando la confianza alcanza el mínimo; si no, escala a una persona. */
    case Auto = 'auto';
}
