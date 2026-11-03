<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/**
 * Solo un administrador activo de la empresa ve, crea, rota y revoca sus llaves de API (y se le exige 2FA como a todo
 * administrador).
 */
class ApiKeyPolicy
{
    public function manage(User $user): bool
    {
        return $user->tenant_id !== null
            && ! $user->is_platform_admin
            && $user->role === Role::Admin
            && $user->disabled_at === null;
    }
}
