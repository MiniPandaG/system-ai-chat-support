<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/**
 * Las métricas y los comentarios de la encuesta de satisfacción los ve solo un administrador activo de la empresa: los
 * comentarios son texto libre de los clientes.
 */
class CsatResponsePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tenant_id !== null
            && ! $user->is_platform_admin
            && $user->role === Role::Admin
            && $user->disabled_at === null;
    }
}
