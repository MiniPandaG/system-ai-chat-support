<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StoredFile;
use App\Models\User;

/**
 * Cualquier miembro activo de la empresa dueña puede ver sus archivos. Nadie más: ni otras empresas ni el personal de
 * la plataforma (que entraría por rutas separadas y auditadas).
 */
class StoredFilePolicy
{
    public function view(User $user, StoredFile $file): bool
    {
        return $user->tenant_id !== null
            && ! $user->is_platform_admin
            && $user->disabled_at === null
            && $user->tenant_id === $file->tenant_id;
    }
}
