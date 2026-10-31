<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Enums\Role;
use App\Http\Requests\UpdateMemberRoleRequest;
use App\Models\Invitation;
use App\Models\User;
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
    public function index(Request $request, TenantContext $context): Response
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
            'invitations' => Invitation::query()->open()->latest()->get()->map(fn (Invitation $invitation): array => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'expired' => $invitation->isExpired(),
                'expires_label' => $invitation->expires_at->isoFormat('D [de] MMM, h:mm a'),
            ])->all(),
        ]);
    }

    public function updateRole(UpdateMemberRoleRequest $request, User $member, AuditLogger $audit): RedirectResponse
    {
        $role = Role::from($request->string('role')->toString());
        $previous = $member->role;

        if ($previous !== $role) {
            $member->forceFill(['role' => $role])->save();

            $audit->record(AuditEvent::MemberRoleChanged, $request->user()?->id, [
                'member_id' => $member->id,
                'from' => $previous?->value,
                'to' => $role->value,
            ]);
        }

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

    public function activate(Request $request, User $member, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('activate', $member);

        $member->forceFill(['disabled_at' => null])->save();

        $audit->record(AuditEvent::MemberActivated, $request->user()?->id, ['member_id' => $member->id]);

        return back()->with('status', __('team.activated'));
    }
}
