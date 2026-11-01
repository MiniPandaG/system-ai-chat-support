<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AddTicketNote;
use App\Http\Requests\StoreTicketNoteRequest;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * Dejar una nota interna en un ticket: solo la ve el equipo que atiende.
 */
class TicketNoteController extends Controller
{
    public function store(StoreTicketNoteRequest $request, Ticket $ticket, AddTicketNote $add): RedirectResponse
    {
        $author = $request->user();
        abort_unless($author instanceof User, 403);

        $add($author, $ticket, $request->string('body')->toString());

        return back();
    }
}
