<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Enums\Role;
use App\Http\Requests\UpdateMemberRoleRequest;
use App\Models\Invitation;
use App\Models\User;
use App\Plans\Limit;
use App\Plans\PlanLimits;
use App\Services\AuditLogger;
use App\Services\UserSessions;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    public function index(Request $request, TenantContext $context, PlanLimits $limits): Response
    {
        Gate::authorize('viewAny', User::class);

        $tenantId = $context->id();
        abort_if($tenantId === null, 404);

        $members = User::query()
            ->inTenant($tenantId)
            ->orderBy('name')
            ->get()
            ->map(fn (User $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => $member->role?->value,
                'active' => $member->disabled_at === null,
                'is_self' => $member->is($request->user()),
                'since' => $member->created_at?->toIso8601String(),
                'since_label' => $member->created_at?->isoFormat('D [de] MMM [de] YYYY'),
            ])
            ->all();

        return Inertia::render('settings/Team', [
            'copy' => __('team'),
            'members' => $members,
            'seats' => $this->seats($context, $limits),
            'invitations' => Invitation::query()->open()->latest()->get()->map(fn (Invitation $invitation): array => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'expired' => $invitation->isExpired(),
                'expires_label' => $invitation->expires_at->isoFormat('D [de] MMM, h:mm a'),
            ])->all(),
        ]);
    }

    /**
     * @return array{plan: string, used: int, max: int|null, remaining: int|null, over: bool}|null
     */
    private function seats(TenantContext $context, PlanLimits $limits): ?array
    {
        $tenant = $context->current();

        if ($tenant === null) {
            return null;
        }

        $agents = $limits->snapshot($tenant)[Limit::Agents->value];

        return [
            'plan' => $limits->planOf($tenant)->name,
            'used' => $agents['used'],
            'max' => $agents['limit'],
            'remaining' => $agents['remaining'],
            'over' => $agents['over'],
        ];
    }

    public function updateRole(UpdateMemberRoleRequest $request, User $member, AuditLogger $audit, TenantContext $context, PlanLimits $limits): RedirectResponse
    {
        $role = Role::from($request->string('role')->toString());
        $previous = $member->role;

        if ($previous === $role) {
            return back()->with('status', __('team.role_updated'));
        }

        $change = function () use ($member, $role, $previous, $request, $audit): void {
            $member->forceFill(['role' => $role])->save();

            $audit->record(AuditEvent::MemberRoleChanged, $request->user()?->id, [
                'member_id' => $member->id,
                'from' => $previous?->value,
                'to' => $role->value,
            ]);
        };

        // Pasar de observador a agente o administrador ocupa un puesto (si el miembro está activo).
        $takesNewSeat = PlanLimits::takesSeat($role) && ! PlanLimits::takesSeat($previous) && $member->disabled_at === null;
        $tenant = $context->current();
        abort_if($tenant === null, 404);

        $takesNewSeat ? $limits->guard($tenant, Limit::Agents, 1, $change) : $change();

        return back()->with('status', __('team.role_updated'));
    }

    public function deactivate(Request $request, User $member, UserSessions $sessions, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('deactivate', $member);

        DB::transaction(function () use ($member, $sessions): void {
            $member->forceFill(['disabled_at' => now()])->save();
            $sessions->revokeAll($member);
        });

        $audit->record(AuditEvent::MemberDeactivated, $request->user()?->id, ['member_id' => $member->id]);

        return back()->with('status', __('team.deactivated'));
    }

    public function activate(Request $request, User $member, AuditLogger $audit, TenantContext $context, PlanLimits $limits): RedirectResponse
    {
        Gate::authorize('activate', $member);

        $reactivate = fn (): bool => $member->forceFill(['disabled_at' => null])->save();
        $tenant = $context->current();
        abort_if($tenant === null, 404);

        // Reactivar a quien tiene un puesto lo vuelve a ocupar.
        PlanLimits::takesSeat($member->role) && $member->disabled_at !== null ? $limits->guard($tenant, Limit::Agents, 1, $reactivate) : $reactivate();

        $audit->record(AuditEvent::MemberActivated, $request->user()?->id, ['member_id' => $member->id]);

        return back()->with('status', __('team.activated'));
    }
}
