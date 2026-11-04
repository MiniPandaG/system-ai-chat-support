<?php

declare(strict_types=1);

namespace App\Ai;

/** Cuándo participa la IA: siempre, solo dentro del horario de la empresa o solo fuera de él. */
enum ScheduleMode: string
{
    case Always = 'always';
    case Within = 'within';
    case Outside = 'outside';
}
