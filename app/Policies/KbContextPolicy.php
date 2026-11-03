<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamMembership;

/**
 * El contexto de la empresa lo escriben solo los administradores y lo leen administradores y agentes: es lo que la IA
 * recibe siempre, así que cambiarlo cambia lo que dice en nombre de la empresa.
 */
class KbContextPolicy
{
    use ChecksTeamMembership;

    public function view(User $actor): bool
    {
        return $this->canHandleTickets($actor);
    }

    public function update(User $actor): bool
    {
        return $this->isActiveMember($actor) && $actor->role === Role::Admin;
    }
}
