<?php

declare(strict_types=1);

namespace App\Actions;

use App\Broadcasting\LiveUpdates;
use App\Models\Tag;
use App\Models\Ticket;

/**
 * Quita una etiqueta de un ticket. La etiqueta sigue existiendo en la empresa para usarla en otros tickets.
 */
class DetachTicketTag
{
    public function __invoke(Ticket $ticket, Tag $tag): void
    {
        if ($ticket->tags()->detach($tag->id) > 0) {
            LiveUpdates::both($ticket);
        }
    }
}
