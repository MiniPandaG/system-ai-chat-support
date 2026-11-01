<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Files\FileStore;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Models\Ticket;
use App\Models\User;
use App\Tickets\Inbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * Plantilla raíz que se carga en la primera visita.
     *
     * @var string
     */
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array{user: array{id: int, name: string, email: string, role: string|null}, tenant: array{id: int, name: string, logo_url: string|null, theme: array<string, string>|null}|null, inbox: array{unassigned: int|null}, can: array{tickets: bool, team: bool, branding: bool, plan: bool}}|null
     */
    private function authenticated(Request $request): ?array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        $tenant = $user->tenant_id === null ? null : Tenant::query()->find($user->tenant_id);

        $canSeeTickets = Gate::forUser($user)->allows('viewAny', Ticket::class);

        return [
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role?->value],
            'tenant' => $tenant === null ? null : ['id' => $tenant->id, 'name' => $tenant->name, 'logo_url' => $this->logoUrl(), 'theme' => $this->theme()],
            // El número que acompaña a «Bandeja» en la barra lateral: tickets pendientes que nadie atiende.
            'inbox' => ['unassigned' => $canSeeTickets ? app(Inbox::class)->unassigned($user) : null],
            'can' => [
                'tickets' => $canSeeTickets,
                'team' => Gate::forUser($user)->allows('viewAny', User::class),
                'branding' => Gate::forUser($user)->allows('manage', TenantBranding::class),
                'plan' => Gate::forUser($user)->allows('viewAny', Plan::class),
            ],
        ];
    }

    /**
     * Variables CSS de la empresa para el panel (solo el primario y su texto, ya validados); nulo con la paleta por defecto.
     *
     * @return array<string, string>|null
     */
    private function theme(): ?array
    {
        $variables = TenantBranding::query()->first()?->panelVariables() ?? [];

        return $variables === [] ? null : $variables;
    }

    /**
     * Enlace firmado del logo de la empresa en contexto; nulo si no subió uno.
     */
    private function logoUrl(): ?string
    {
        $logo = TenantBranding::query()->first()?->logo();

        return $logo === null ? null : app(FileStore::class)->temporaryUrl($logo);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => fn (): ?array => $this->authenticated($request),
            'panel' => fn (): array => (array) __('panel'),
            'flash' => [
                'status' => fn (): ?string => is_string($status = $request->session()->get('status')) ? $status : null,
                // Códigos de recuperación recién emitidos: se entregan una sola vez.
                'recovery_codes' => fn (): ?array => is_array($codes = $request->session()->get('recovery_codes')) ? array_values($codes) : null,
            ],
        ];
    }
}
