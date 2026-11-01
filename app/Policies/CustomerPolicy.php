<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksTeamMembership;

/**
 * Buscar clientes lo hace quien abre tickets: administradores y agentes. Los observadores no necesitan el buscador.
 */
class CustomerPolicy
{
    use ChecksTeamMembership;

    public function viewAny(User $actor): bool
    {
        return $this->canHandleTickets($actor);
    }
}
