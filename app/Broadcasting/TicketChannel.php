<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Autoriza entrar al canal de un ticket: la empresa del canal debe ser la del usuario, el ticket debe existir dentro de
 * esa empresa (la búsqueda usa el filtro automático, así que uno de otra empresa «no existe») y la política debe dejar
 * al usuario verlo.
 */
class TicketChannel
{
    public function join(User $user, string $tenantId, string $ticketId): bool
    {
        if (! ChannelId::matches($tenantId, $user->tenant_id) || ! ChannelId::isId($ticketId)) {
            return false;
        }

        $ticket = Ticket::query()->find((int) $ticketId);

        return $ticket !== null && Gate::forUser($user)->allows('view', $ticket);
    }
}
