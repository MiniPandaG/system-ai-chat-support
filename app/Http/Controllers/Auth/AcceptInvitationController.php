<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\AcceptInvitationRequest;
use App\Models\Invitation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InvitationService;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Aceptación pública de una invitación. Un enlace inexistente, caducado, usado o cancelado recibe exactamente la misma
 * respuesta, para no revelar cuál de los casos es.
 */
class AcceptInvitationController extends Controller
{
    public function __construct(
        private readonly InvitationService $invitations,
        private readonly TenantContext $context,
    ) {}

    public function show(string $token): Response
    {
        $invitation = $this->invitations->findByToken($token);
        $usable = $invitation !== null && $invitation->isUsable();

        return Inertia::render('auth/AcceptInvitation', [
            'copy' => __('auth.invitation'),
            'token' => $token,
            'invitation' => $usable ? $this->summary($invitation) : null,
        ]);
    }

    public function store(AcceptInvitationRequest $request, string $token, AuditLogger $audit): RedirectResponse
    {
        $found = $this->invitations->findByToken($token);
        $tenant = $found === null ? null : Tenant::query()->find($found->tenant_id);

        if ($found === null || $tenant === null) {
            throw $this->invalid();
        }

        $user = $this->context->run($tenant, fn (): User => $this->accept($token, $request->string('name')->toString(), $request->string('password')->toString()));

        $audit->record(AuditEvent::InvitationAccepted, $user->id, ['tenant_id' => $user->tenant_id]);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->route('account');
    }

    private function accept(string $token, string $name, string $password): User
    {
        return DB::transaction(function () use ($token, $name, $password): User {
            // El bloqueo de fila hace que dos aceptaciones a la vez no puedan crear dos cuentas con el mismo enlace.
            $invitation = Invitation::query()->where('token_hash', Invitation::hashToken($token))->lockForUpdate()->first();

            if ($invitation === null || ! $invitation->isUsable()) {
                throw $this->invalid();
            }

            if (User::query()->where('email', $invitation->email)->exists()) {
                throw ValidationException::withMessages(['token' => [__('auth.invitation.exists')]]);
            }

            $user = new User(['name' => $name, 'email' => $invitation->email, 'password' => $password]);
            // El enlace llegó al correo de la persona: eso prueba que es suyo, así que nace verificado.
            $user->forceFill(['tenant_id' => $invitation->tenant_id, 'role' => $invitation->role, 'email_verified_at' => now()])->save();

            $invitation->forceFill(['accepted_at' => now()])->save();

            return $user;
        });
    }

    private function invalid(): ValidationException
    {
        return ValidationException::withMessages(['token' => [__('auth.invitation.invalid')]]);
    }

    /**
     * @return array{email: string, role: string, company: string}
     */
    private function summary(Invitation $invitation): array
    {
        return [
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'company' => Tenant::query()->find($invitation->tenant_id)->name ?? '',
        ];
    }
}
