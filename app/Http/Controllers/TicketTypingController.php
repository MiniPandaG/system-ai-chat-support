<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Broadcasting\LiveUpdates;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * «Estoy escribiendo»: el navegador pide al servidor que lo anuncie y el servidor lo hace si la persona puede responder
 * en ese ticket. El ticket se resuelve con el filtro de empresa (404 si es de otra). Sin contenido y sin estado.
 */
class TicketTypingController extends Controller
{
    public function store(Request $request, Ticket $ticket): Response
    {
        Gate::authorize('reply', $ticket);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        LiveUpdates::typing($ticket, $user);

        return response()->noContent();
    }
}
