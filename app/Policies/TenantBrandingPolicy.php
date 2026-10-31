<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/**
 * Solo un administrador activo de la empresa cambia su marca.
 */
class TenantBrandingPolicy
{
    public function manage(User $user): bool
    {
        return $user->tenant_id !== null
            && ! $user->is_platform_admin
            && $user->role === Role::Admin
            && $user->disabled_at === null;
    }
}
