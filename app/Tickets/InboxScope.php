<?php

declare(strict_types=1);

namespace App\Tickets;

/**
 * Los filtros rápidos fijos de la bandeja. «Asignados a mí» y «Sin asignar» muestran lo que sigue pendiente: si no se
 * elige un estado, dejan fuera lo resuelto y lo cerrado.
 */
enum InboxScope: string
{
    case All = 'all';
    case Mine = 'mine';
    case Unassigned = 'unassigned';
}
