<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Autoriza entrar al canal de la bandeja de una empresa: solo un miembro activo de esa misma empresa que pueda ver la
 * bandeja. La política es la misma que protege la pantalla (denegar por defecto), y la empresa del canal debe ser la del
 * usuario: el identificador del canal lo escribe el navegador, así que no se confía en él.
 */
class InboxChannel
{
    public function join(User $user, string $tenantId): bool
    {
        return ChannelId::matches($tenantId, $user->tenant_id)
            && Gate::forUser($user)->allows('viewAny', Ticket::class);
    }
}
