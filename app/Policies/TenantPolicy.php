<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Administrar las empresas desde la plataforma: solo el personal de la plataforma (sin empresa y con el distintivo),
 * nunca un administrador de empresa. Todo lo no previsto se deniega.
 */
class TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isPlatformStaff($user);
    }

    public function changePlan(User $user): bool
    {
        return $this->isPlatformStaff($user);
    }

    private function isPlatformStaff(User $user): bool
    {
        return $user->is_platform_admin && $user->tenant_id === null && $user->disabled_at === null;
    }
}
