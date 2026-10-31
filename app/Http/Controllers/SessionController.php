<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\UserSessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SessionController extends Controller
{
    public function __construct(
        private readonly UserSessions $sessions,
        private readonly AuditLogger $audit,
    ) {}

    public function destroy(Request $request, string $handle): RedirectResponse
    {
        $user = $this->user($request);

        // Un código que no es de una sesión ajena de esta cuenta es igual a uno inexistente.
        abort_unless($this->sessions->revoke($user, $handle, $request->session()->getId()), 404);

        $this->audit->record(AuditEvent::SessionRevoked, $user->id);

        return back()->with('status', __('security.sessions.revoked'));
    }

    public function destroyOthers(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $closed = $this->sessions->revokeOthers($user, $request->session()->getId());

        $this->audit->record(AuditEvent::SessionsRevoked, $user->id, ['sessions_closed' => $closed, 'includes_current' => false]);

        return back()->with('status', __('security.sessions.revoked_others'));
    }

    public function destroyAll(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $closed = $this->sessions->revokeAll($user);

        $this->audit->record(AuditEvent::SessionsRevoked, $user->id, ['sessions_closed' => $closed, 'includes_current' => true]);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', __('security.sessions.revoked_all'));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
