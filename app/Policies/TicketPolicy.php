<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamMembership;

/**
 * Todo el equipo activo de la empresa ve la bandeja; solo administradores y agentes crean y modifican. El filtro
 * automático ya deja fuera los tickets de otras empresas; aquí se vuelve a comprobar por si algún día se consulta sin él.
 */
class TicketPolicy
{
    use ChecksTeamMembership;

    public function viewAny(User $actor): bool
    {
        return $this->isActiveMember($actor);
    }

    public function view(User $actor, Ticket $ticket): bool
    {
        return $this->isActiveMember($actor) && $actor->tenant_id === $ticket->tenant_id;
    }

    public function create(User $actor): bool
    {
        return $this->canHandleTickets($actor);
    }

    public function update(User $actor, Ticket $ticket): bool
    {
        return $this->canHandleTickets($actor) && $actor->tenant_id === $ticket->tenant_id;
    }

    /** Responder al cliente, dejar notas y etiquetar: lo hace quien atiende. Los observadores no. */
    public function reply(User $actor, Ticket $ticket): bool
    {
        return $this->update($actor, $ticket);
    }

    public function addNote(User $actor, Ticket $ticket): bool
    {
        return $this->update($actor, $ticket);
    }

    public function tag(User $actor, Ticket $ticket): bool
    {
        return $this->update($actor, $ticket);
    }

    /** Las notas internas son del equipo que atiende: un observador ve la conversación, no lo que el equipo se dice. */
    public function viewNotes(User $actor, Ticket $ticket): bool
    {
        return $this->update($actor, $ticket);
    }
}
