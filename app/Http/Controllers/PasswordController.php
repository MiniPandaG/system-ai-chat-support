<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Http\Requests\UpdatePasswordRequest;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Services\AuditLogger;
use App\Services\UserSessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class PasswordController extends Controller
{
    public function update(UpdatePasswordRequest $request, UserSessions $sessions, AuditLogger $audit): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $closed = DB::transaction(function () use ($request, $user, $sessions): int {
            $user->forceFill(['password' => $request->string('password')->toString()])->save();

            // Quien tuviera la contraseña vieja pierde cualquier otra sesión abierta.
            return $sessions->revokeOthers($user, $request->session()->getId());
        });

        // Se destruye también la sesión vieja: si el identificador anterior estaba comprometido, no debe seguir sirviendo.
        $request->session()->regenerate(destroy: true);

        $audit->record(AuditEvent::PasswordChanged, $user->id, ['sessions_closed' => $closed]);
        $user->notify(new PasswordChangedNotification);

        return back()->with('status', __('security.password.changed'));
    }
}
