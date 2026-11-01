<?php

declare(strict_types=1);

namespace App\Tickets;

enum InboxSort: string
{
    /** El último mensaje más reciente primero. */
    case Recent = 'recent';

    /** El ticket más antiguo primero. */
    case Oldest = 'oldest';

    /** Lo más urgente primero y, dentro de cada prioridad, lo más reciente. */
    case Priority = 'priority';
}
