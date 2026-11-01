<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AttachTicketTag;
use App\Actions\DetachTicketTag;
use App\Http\Requests\AttachTicketTagRequest;
use App\Models\Tag;
use App\Models\Ticket;
use App\Tenancy\TenantContext;
use App\Tickets\TagTone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Etiquetas de un ticket. La etiqueta y el ticket se resuelven con el filtro de empresa: una de otra empresa da 404.
 */
class TicketTagController extends Controller
{
    public function store(AttachTicketTagRequest $request, Ticket $ticket, AttachTicketTag $attach, TenantContext $context): RedirectResponse
    {
        $tenant = $context->current();
        abort_if($tenant === null, 403);

        $tone = $request->filled('tone') ? TagTone::from($request->string('tone')->toString()) : TagTone::Neutral;

        $attach(
            $tenant,
            $ticket,
            $request->filled('tag_id') ? $request->integer('tag_id') : null,
            $request->filled('name') ? $request->string('name')->toString() : null,
            $tone,
        );

        return back();
    }

    public function destroy(Ticket $ticket, Tag $tag, DetachTicketTag $detach): RedirectResponse
    {
        Gate::authorize('tag', $ticket);

        $detach($ticket, $tag);

        return back();
    }
}
