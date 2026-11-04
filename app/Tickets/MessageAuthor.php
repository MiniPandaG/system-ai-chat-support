<?php

declare(strict_types=1);

namespace App\Tickets;

enum MessageAuthor: string
{
    case Customer = 'customer';
    case Agent = 'agent';

    /** La IA: un borrador aprobado por una persona sin cambios. Si la persona lo edita, el autor es ella. */
    case Ai = 'ai';
}
