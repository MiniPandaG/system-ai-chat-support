<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/**
 * Ver el plan y su uso: solo un administrador activo de la empresa. Nadie cambia el plan desde la interfaz (lo hace
 * el equipo de la plataforma con plans:assign), así que no hay ninguna otra habilidad.
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
}
