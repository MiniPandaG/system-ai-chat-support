<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\TicketView;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamMembership;

/**
 * Administradores y agentes guardan vistas, privadas o compartidas. Quien creó una vista la borra; una vista
 * compartida también la puede borrar un administrador de la empresa. Los observadores solo usan las compartidas.
 */
class TicketViewPolicy
{
    use ChecksTeamMembership;

    public function create(User $actor): bool
    {
        return $this->canHandleTickets($actor);
    }

    public function delete(User $actor, TicketView $view): bool
    {
        if (! $this->isActiveMember($actor) || $actor->tenant_id !== $view->tenant_id) {
            return false;
        }

        return $view->user_id === $actor->id || ($view->shared && $actor->role === Role::Admin);
    }
}
