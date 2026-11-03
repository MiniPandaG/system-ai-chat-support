<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Models\KbArticle;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Autoriza entrar al canal de la base de conocimiento de una empresa: solo un miembro activo de esa misma empresa que
 * pueda leerla. El identificador del canal lo escribe el navegador, así que se compara con la empresa del usuario.
 */
class KnowledgeChannel
{
    public function join(User $user, string $tenantId): bool
    {
        return ChannelId::matches($tenantId, $user->tenant_id)
            && Gate::forUser($user)->allows('viewAny', KbArticle::class);
    }
}
