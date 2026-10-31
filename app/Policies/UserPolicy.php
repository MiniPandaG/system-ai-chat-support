<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/**
 * Gestión del equipo de una empresa. Solo un administrador activo de la misma empresa puede hacerlo, y nunca sobre
 * sí mismo: así una empresa siempre conserva al menos un administrador. Todo lo no previsto se deniega.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->isActiveCompanyAdmin($actor);
    }

    public function changeRole(User $actor, User $member): bool
    {
        return $this->manages($actor, $member);
    }

    public function deactivate(User $actor, User $member): bool
    {
        return $this->manages($actor, $member);
    }

    public function activate(User $actor, User $member): bool
    {
        return $this->manages($actor, $member);
    }

    private function manages(User $actor, User $member): bool
    {
        return $this->isActiveCompanyAdmin($actor)
            && $actor->tenant_id === $member->tenant_id
            && $actor->isNot($member);
    }

    private function isActiveCompanyAdmin(User $user): bool
    {
        return $user->tenant_id !== null
            && ! $user->is_platform_admin
            && $user->role === Role::Admin
            && $user->disabled_at === null;
    }
}
