<?php

declare(strict_types=1);

namespace App\Http\Requests;

class StoreTicketNoteRequest extends TicketBodyRequest
{
    protected function ability(): string
    {
        return 'addNote';
    }
}
