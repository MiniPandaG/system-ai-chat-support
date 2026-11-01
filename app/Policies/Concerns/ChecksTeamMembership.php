<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\Role;
use App\Models\User;

/**
 * Quién cuenta como miembro del equipo de una empresa: con empresa, activo y que no es personal de la plataforma.
 */
trait ChecksTeamMembership
{
    private function isActiveMember(User $user): bool
    {
        return $user->tenant_id !== null && ! $user->is_platform_admin && $user->disabled_at === null && $user->role !== null;
    }

    /** Administradores y agentes: quienes atienden. Los observadores solo leen. */
    private function canHandleTickets(User $user): bool
    {
        return $this->isActiveMember($user) && ($user->role === Role::Admin || $user->role === Role::Agent);
    }
}
