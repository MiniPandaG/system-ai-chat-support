<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\Invitation;
use App\Models\User;

/**
 * Solo un administrador activo de la empresa invita, reenvía o cancela. El filtro automático ya deja fuera las
 * invitaciones de otras empresas; aquí se vuelve a comprobar por si algún día se consulta sin él.
 */
class InvitationPolicy
{
    public function create(User $actor): bool
    {
        return $this->isActiveCompanyAdmin($actor);
    }

    public function resend(User $actor, Invitation $invitation): bool
    {
        return $this->manages($actor, $invitation);
    }

    public function revoke(User $actor, Invitation $invitation): bool
    {
        return $this->manages($actor, $invitation);
    }

    private function manages(User $actor, Invitation $invitation): bool
    {
        return $this->isActiveCompanyAdmin($actor) && $actor->tenant_id === $invitation->tenant_id;
    }

    private function isActiveCompanyAdmin(User $user): bool
    {
        return $user->tenant_id !== null
            && ! $user->is_platform_admin
            && $user->role === Role::Admin
            && $user->disabled_at === null;
    }
}
