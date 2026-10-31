<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Enums\Role;
use App\Http\Requests\StoreInvitationRequest;
use App\Models\Invitation;
use App\Models\User;
use App\Plans\Limit;
use App\Plans\PlanLimits;
use App\Services\AuditLogger;
use App\Services\InvitationService;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Invitaciones desde el panel: crear, reenviar y cancelar. Las invitaciones se resuelven con el filtro automático, así
 * que una de otra empresa da 404.
 */
class InvitationController extends Controller
{
    /** Invitaciones que una empresa puede enviar por hora. */
    private const HOURLY_LIMIT = 20;

    public function store(StoreInvitationRequest $request, InvitationService $invitations, AuditLogger $audit, TenantContext $context, PlanLimits $limits): RedirectResponse
    {
        $inviter = $request->user();
        $tenantId = $context->id();
        abort_unless($inviter instanceof User && $tenantId !== null, 403);

        $role = Role::from($request->string('role')->toString());
        $emails = [];
        foreach ((array) $request->input('emails', []) as $candidate) {
            if (is_string($candidate)) {
                $emails[] = mb_strtolower(trim($candidate));
            }
        }
        $emails = array_values(array_unique($emails));

        $members = array_values(array_filter(
            User::query()->inTenant($tenantId)->whereIn('email', $emails)->pluck('email')->all(),
            is_string(...),
        ));
        $toInvite = array_values(array_diff($emails, $members));

        $key = 'invitations:'.$tenantId;
        $used = RateLimiter::attempts($key);
        if ((is_int($used) ? $used : 0) + count($toInvite) > self::HOURLY_LIMIT) {
            throw ValidationException::withMessages(['emails' => [__('team.invite_limit')]]);
        }

        $send = function () use ($toInvite, $key, $invitations, $inviter, $role, $audit): void {
            foreach ($toInvite as $email) {
                RateLimiter::hit($key, 3600);

                $invitation = $invitations->invite($inviter, $email, $role);

                $audit->record(AuditEvent::InvitationCreated, $inviter->id, ['invitation_id' => $invitation->id, 'role' => $role->value]);
            }
        };

        // Los puestos que hacen falta: uno por cada persona nueva en un rol con puesto, salvo quien ya tiene una
        // invitación abierta que lo reserva. Si no caben todos, no se envía ninguno.
        $seats = PlanLimits::takesSeat($role) ? $this->newSeatsNeeded($toInvite) : 0;
        $tenant = $context->current();
        abort_if($tenant === null, 403);

        $seats > 0 ? $limits->guard($tenant, Limit::Agents, $seats, $send) : $send();

        $redirect = back()->with('status', trans_choice('team.invite_sent', count($toInvite), ['count' => count($toInvite)]));

        if ($members !== []) {
            $redirect->withErrors(['emails' => __('team.invite_already_members', ['emails' => implode(', ', $members)])]);
        }

        return $redirect;
    }

    /**
     * @param  list<string>  $emails
     */
    private function newSeatsNeeded(array $emails): int
    {
        $holding = array_values(array_filter(
            Invitation::query()->open()->where('expires_at', '>', now())->whereIn('email', $emails)->whereIn('role', ['admin', 'agent'])->pluck('email')->all(),
            is_string(...),
        ));

        return count(array_diff($emails, $holding));
    }

    public function resend(Request $request, Invitation $invitation, InvitationService $invitations, AuditLogger $audit, TenantContext $context, PlanLimits $limits): RedirectResponse
    {
        Gate::authorize('resend', $invitation);
        abort_unless($invitation->accepted_at === null && $invitation->revoked_at === null, 404);

        $resend = fn () => $invitations->resend($invitation);
        $tenant = $context->current();
        abort_if($tenant === null, 403);

        // Reenviar una invitación caducada la vuelve a abrir y a reservar su puesto.
        $invitation->isExpired() && PlanLimits::takesSeat($invitation->role) ? $limits->guard($tenant, Limit::Agents, 1, $resend) : $resend();

        $audit->record(AuditEvent::InvitationResent, $request->user()?->id, ['invitation_id' => $invitation->id]);

        return back()->with('status', __('team.invite_resent'));
    }

    public function revoke(Request $request, Invitation $invitation, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('revoke', $invitation);
        abort_unless($invitation->accepted_at === null && $invitation->revoked_at === null, 404);

        $invitation->forceFill(['revoked_at' => now()])->save();

        $audit->record(AuditEvent::InvitationRevoked, $request->user()?->id, ['invitation_id' => $invitation->id]);

        return back()->with('status', __('team.invite_revoked'));
    }
}
