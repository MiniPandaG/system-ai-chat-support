<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Role;
use App\Models\Invitation;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Crea, reenvía y busca invitaciones. El token en claro solo existe aquí y en el correo; en la base queda su hash.
 */
class InvitationService
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Invita a un correo. Si ya había una invitación abierta para él, se reutiliza con un token nuevo, de modo que
     * el enlace anterior deja de servir.
     */
    public function invite(User $inviter, string $email, Role $role): Invitation
    {
        $email = mb_strtolower(trim($email));
        $invitation = Invitation::query()->open()->where('email', $email)->first() ?? new Invitation(['email' => $email]);
        $invitation->role = $role;
        $invitation->invited_by = $inviter->id;

        $this->issueToken($invitation);

        return $invitation;
    }

    public function resend(Invitation $invitation): void
    {
        $this->issueToken($invitation);
    }

    /**
     * Busca por el token del enlace, que es una petición pública sin empresa en contexto. Por eso se consulta primero
     * solo la empresa y la invitación se lee ya dentro de ella, con el filtro automático puesto.
     */
    public function findByToken(string $token): ?Invitation
    {
        $hash = Invitation::hashToken($token);
        $tenantId = DB::table('invitations')->where('token_hash', $hash)->value('tenant_id');
        $tenant = is_int($tenantId) ? Tenant::query()->find($tenantId) : null;

        if ($tenant === null) {
            return null;
        }

        return $this->context->run($tenant, fn (): ?Invitation => Invitation::query()->where('token_hash', $hash)->first());
    }

    private function issueToken(Invitation $invitation): void
    {
        $token = Str::random(64);

        $invitation->token_hash = Invitation::hashToken($token);
        $invitation->expires_at = now()->addHours(Invitation::TTL_HOURS);
        $invitation->save();

        $company = $this->context->current()->name ?? '';

        Notification::route('mail', $invitation->email)->notify(new InvitationNotification($token, $company, $invitation->role));
    }
}
