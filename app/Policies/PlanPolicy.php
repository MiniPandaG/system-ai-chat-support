<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/**
 * Ver el plan y su uso: solo un administrador activo de la empresa. El catálogo de planes (crear, cambiar límites,
 * activar y elegir el de fábrica) lo administra solo el personal de la plataforma.
 */
class PlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tenant_id !== null
            && ! $user->is_platform_admin
            && $user->role === Role::Admin
            && $user->disabled_at === null;
    }

    public function manage(User $user): bool
    {
        return $user->is_platform_admin && $user->tenant_id === null && $user->disabled_at === null;
    }
}
