<?php

declare(strict_types=1);

namespace App\Tickets;

enum TicketStatus: string
{
    case Open = 'open';
    case WaitingCustomer = 'waiting_customer';
    case Resolved = 'resolved';
    case Closed = 'closed';
}
