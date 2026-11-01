<?php

declare(strict_types=1);

namespace App\Tickets;

enum MessageAuthor: string
{
    case Customer = 'customer';
    case Agent = 'agent';
}
