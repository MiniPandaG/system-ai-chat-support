<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ApproveAiDraft;
use App\Actions\DiscardAiDraft;
use App\Http\Requests\ApproveAiDraftRequest;
use App\Models\AiRun;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Revisar el borrador que la IA preparó para un ticket. El ticket y la ejecución se resuelven con el filtro de empresa
 * (404 si son de otra) y la ejecución debe ser de ese ticket.
 */
class AiDraftController extends Controller
{
    public function approve(ApproveAiDraftRequest $request, Ticket $ticket, AiRun $run, ApproveAiDraft $approve): RedirectResponse
    {
        abort_if($run->ticket_id !== $ticket->id, 404);

        $approve($this->user($request), $ticket, $run, $request->editedBody());

        return back();
    }

    public function discard(Request $request, Ticket $ticket, AiRun $run, DiscardAiDraft $discard): RedirectResponse
    {
        Gate::authorize('reply', $ticket);
        abort_if($run->ticket_id !== $ticket->id, 404);

        $discard($this->user($request), $ticket, $run);

        return back();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
