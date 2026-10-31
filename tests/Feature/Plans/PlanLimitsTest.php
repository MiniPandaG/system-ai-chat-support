<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Invitation;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Plans\Limit;
use App\Plans\LimitReached;
use App\Plans\PlanLimits;
use App\Services\InvitationService;
use App\Tenancy\TenantContext;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    Notification::fake();
});

/** Empresa en un plan con su administrador (que ocupa un puesto) y 2FA, para llegar a las rutas. */
function seatTenant(string $plan = 'inicial'): Tenant
{
    $tenant = Tenant::factory()->onPlan($plan)->create();
    $admin = User::factory()->forTenant($tenant, Role::Admin)->create();
    $admin->forceFill([
        'two_factor_secret' => encrypt('SECRETOPRUEBA'),
        'two_factor_recovery_codes' => encrypt(json_encode(['x'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $tenant;
}

function adminOf(Tenant $tenant): User
{
    return User::query()->inTenant($tenant->id)->where('role', Role::Admin->value)->firstOrFail();
}

function addMember(Tenant $tenant, Role $role, bool $disabled = false): User
{
    $user = User::factory()->forTenant($tenant, $role)->create();

    if ($disabled) {
        $user->forceFill(['disabled_at' => now()])->save();
    }

    return $user;
}

function seatsUsed(Tenant $tenant): int
{
    return app(PlanLimits::class)->usage($tenant, Limit::Agents);
}

function invitationOf(Tenant $tenant, string $email, Role $role, ?int $hoursUntilExpiry = null): Invitation
{
    $invitation = app(TenantContext::class)->run($tenant, fn (): Invitation => app(InvitationService::class)->invite(adminOf($tenant), $email, $role));

    if ($hoursUntilExpiry !== null) {
        DB::table('invitations')->where('id', $invitation->id)->update(['expires_at' => now()->addHours($hoursUntilExpiry)]);
    }

    return $invitation;
}

function limitsToken(string $email): string
{
    $token = null;

    Notification::assertSentOnDemand(InvitationNotification::class, function (InvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($email, &$token): bool {
        if (($notifiable->routes['mail'] ?? null) !== $email) {
            return false;
        }

        $token = (fn (): string => $this->token)->call($notification);

        return true;
    });

    return (string) $token;
}

function invitePayload(array $emails, string $role = 'agent'): array
{
    return ['emails' => $emails, 'role' => $role];
}

describe('uso de puestos', function (): void {
    it('cuenta a los administradores y agentes activos, no a observadores ni desactivados', function (): void {
        $tenant = seatTenant('negocio');
        addMember($tenant, Role::Agent);
        addMember($tenant, Role::Observer);
        addMember($tenant, Role::Agent, disabled: true);

        expect(seatsUsed($tenant))->toBe(2);
    });

    it('las invitaciones abiertas con puesto lo reservan; las caducadas, aceptadas, canceladas y de observador no', function (): void {
        $tenant = seatTenant('negocio');
        invitationOf($tenant, 'abierta@empresa-ejemplo.com', Role::Agent);
        invitationOf($tenant, 'observadora@empresa-ejemplo.com', Role::Observer);
        invitationOf($tenant, 'caducada@empresa-ejemplo.com', Role::Agent, hoursUntilExpiry: -1);
        $revoked = invitationOf($tenant, 'cancelada@empresa-ejemplo.com', Role::Agent);
        $accepted = invitationOf($tenant, 'aceptada@empresa-ejemplo.com', Role::Agent);
        DB::table('invitations')->where('id', $revoked->id)->update(['revoked_at' => now()]);
        DB::table('invitations')->where('id', $accepted->id)->update(['accepted_at' => now()]);

        expect(seatsUsed($tenant))->toBe(2);
    });

    it('cada empresa cuenta solo lo suyo', function (): void {
        $a = seatTenant('negocio');
        $b = seatTenant('negocio');
        addMember($a, Role::Agent);
        invitationOf($a, 'x@empresa-ejemplo.com', Role::Agent);

        expect(seatsUsed($a))->toBe(3)->and(seatsUsed($b))->toBe(1);
    });

    it('lo que no existe todavía (canales, tickets, créditos) se reporta en cero', function (): void {
        $tenant = seatTenant();
        $limits = app(PlanLimits::class);

        expect($limits->usage($tenant, Limit::Channels))->toBe(0)
            ->and($limits->usage($tenant, Limit::TicketsPerMonth))->toBe(0)
            ->and($limits->usage($tenant, Limit::AiCredits))->toBe(0);
    });
});

describe('servicio', function (): void {
    it('lee el tope del plan de la empresa, queda lo que falta y distingue pasarse', function (): void {
        $tenant = seatTenant();
        $limits = app(PlanLimits::class);

        expect($limits->limit($tenant, Limit::Agents))->toBe(2)
            ->and($limits->remaining($tenant, Limit::Agents))->toBe(1)
            ->and($limits->allows($tenant, Limit::Agents))->toBeTrue()
            ->and($limits->allows($tenant, Limit::Agents, 2))->toBeFalse()
            ->and($limits->isOver($tenant, Limit::Agents))->toBeFalse();

        addMember($tenant, Role::Agent);

        expect($limits->remaining($tenant, Limit::Agents))->toBe(0)
            ->and($limits->allows($tenant, Limit::Agents))->toBeFalse()
            ->and($limits->allows($tenant, Limit::Agents, 0))->toBeTrue();

        Plan::query()->where('slug', 'inicial')->update(['max_agents' => 1]);

        expect($limits->isOver($tenant, Limit::Agents))->toBeTrue()->and($limits->remaining($tenant, Limit::Agents))->toBe(0)->and($limits->allows($tenant, Limit::Agents, 0))->toBeFalse();
    });

    it('un límite nulo es sin límite', function (): void {
        $tenant = seatTenant();
        Plan::query()->where('slug', 'inicial')->update(['max_agents' => null]);
        $limits = app(PlanLimits::class);

        for ($i = 0; $i < 5; $i++) {
            addMember($tenant, Role::Agent);
        }

        expect($limits->limit($tenant, Limit::Agents))->toBeNull()
            ->and($limits->remaining($tenant, Limit::Agents))->toBeNull()
            ->and($limits->allows($tenant, Limit::Agents, 1000))->toBeTrue()
            ->and($limits->isOver($tenant, Limit::Agents))->toBeFalse();
    });

    it('ensure lanza con un mensaje claro que dice el plan, el tope y el uso', function (): void {
        $tenant = seatTenant();
        addMember($tenant, Role::Agent);

        try {
            app(PlanLimits::class)->ensure($tenant, Limit::Agents);
            $this->fail('Debió lanzar LimitReached.');
        } catch (LimitReached $reached) {
            expect($reached->getMessage())->toContain('Tu plan Inicial permite 2 puestos de agente y ya usas 2')
                ->toContain('Los observadores no ocupan puesto')->toContain('cambio de plan')
                ->and($reached->limit)->toBe(Limit::Agents)->and($reached->max)->toBe(2)->and($reached->used)->toBe(2);
        }
    });

    it('el mensaje cambia si quedan puestos pero no alcanzan para todos', function (): void {
        $tenant = seatTenant('equipo');

        try {
            app(PlanLimits::class)->ensure($tenant, Limit::Agents, 6);
            $this->fail('Debió lanzar LimitReached.');
        } catch (LimitReached $reached) {
            expect($reached->getMessage())->toContain('solo tiene 4 puestos de agente libres y quieres añadir 6');
        }
    });

    it('el mensaje distingue un plan de un solo puesto y uno sin puestos', function (int $max, string $expected): void {
        $tenant = seatTenant();
        Plan::query()->where('slug', 'inicial')->update(['max_agents' => $max]);

        try {
            app(PlanLimits::class)->ensure($tenant, Limit::Agents);
            $this->fail('Debió lanzar LimitReached.');
        } catch (LimitReached $reached) {
            expect($reached->getMessage())->toContain($expected);
        }
    })->with([
        'cero' => [0, 'no incluye puestos de agente'],
        'uno' => [1, 'permite 1 puesto de agente y ya está en uso'],
    ]);

    it('guard bloquea la fila de la empresa mientras comprueba y escribe', function (): void {
        $tenant = seatTenant();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        app(PlanLimits::class)->guard($tenant, Limit::Agents, 1, fn (): string => 'hecho');

        $locks = array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from `tenants`') && str_contains($sql, 'for update'));

        expect($locks)->not->toBeEmpty();
    });

    it('si la acción falla dentro de guard no queda nada a medias', function (): void {
        $tenant = seatTenant('equipo');

        expect(fn () => app(PlanLimits::class)->guard($tenant, Limit::Agents, 1, function () use ($tenant): void {
            addMember($tenant, Role::Agent);
            throw new RuntimeException('falla a mitad');
        }))->toThrow(RuntimeException::class);

        expect(seatsUsed($tenant))->toBe(1);
    });
});

describe('invitar', function (): void {
    it('se bloquea al llegar al tope con el mensaje del plan, y no se crea ni envía nada', function (): void {
        $tenant = seatTenant();
        addMember($tenant, Role::Agent);

        $this->actingAs(adminOf($tenant))->post('/settings/team/invitations', invitePayload(['nueva@empresa-ejemplo.com']))
            ->assertSessionHasErrors(['limit' => 'Tu plan Inicial permite 2 puestos de agente y ya usas 2. Los observadores no ocupan puesto. Para añadir más agentes o administradores, pide un cambio de plan al equipo de TikaroChat.']);

        expect(app(TenantContext::class)->run($tenant, fn (): int => Invitation::query()->count()))->toBe(0);
        Notification::assertNothingSent();
    });

    it('invitar a un administrador también pide puesto, pero a un observador no', function (): void {
        $tenant = seatTenant();
        addMember($tenant, Role::Agent);
        $admin = adminOf($tenant);

        $this->actingAs($admin)->post('/settings/team/invitations', invitePayload(['jefe@empresa-ejemplo.com'], 'admin'))->assertSessionHasErrors('limit');
        $this->actingAs($admin)->post('/settings/team/invitations', invitePayload(['mira@empresa-ejemplo.com'], 'observer'))
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Enviamos 1 invitación.');
    });

    it('con un puesto libre deja invitar a uno y la siguiente ya no', function (): void {
        $tenant = seatTenant();
        $admin = adminOf($tenant);

        $this->actingAs($admin)->post('/settings/team/invitations', invitePayload(['uno@empresa-ejemplo.com']))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/settings/team/invitations', invitePayload(['dos@empresa-ejemplo.com']))->assertSessionHasErrors('limit');

        expect(seatsUsed($tenant))->toBe(2);
    });

    it('si no caben todos los invitados no se envía ninguno', function (): void {
        $tenant = seatTenant();

        $this->actingAs(adminOf($tenant))->post('/settings/team/invitations', invitePayload(['a@empresa-ejemplo.com', 'b@empresa-ejemplo.com']))
            ->assertSessionHasErrors('limit');

        expect(session('errors')?->first('limit'))->toContain('solo tiene 1 puesto de agente libre y quieres añadir 2');

        expect(app(TenantContext::class)->run($tenant, fn (): int => Invitation::query()->count()))->toBe(0);
        Notification::assertNothingSent();
    });

    it('volver a invitar al mismo correo no gasta otro puesto', function (): void {
        $tenant = seatTenant();
        $admin = adminOf($tenant);

        $this->actingAs($admin)->post('/settings/team/invitations', invitePayload(['uno@empresa-ejemplo.com']))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/settings/team/invitations', invitePayload(['uno@empresa-ejemplo.com']))->assertSessionHasNoErrors();

        expect(seatsUsed($tenant))->toBe(2);
    });

    it('una invitación caducada libera su puesto', function (): void {
        $tenant = seatTenant();
        invitationOf($tenant, 'vieja@empresa-ejemplo.com', Role::Agent);

        $this->actingAs(adminOf($tenant))->post('/settings/team/invitations', invitePayload(['otra@empresa-ejemplo.com']))->assertSessionHasErrors('limit');

        $this->travel(73)->hours();

        $this->actingAs(adminOf($tenant))->post('/settings/team/invitations', invitePayload(['otra@empresa-ejemplo.com']))->assertSessionHasNoErrors();
    });

    it('reenviar una invitación caducada pide puesto de nuevo, y una vigente no', function (): void {
        $tenant = seatTenant();
        $admin = adminOf($tenant);
        $expired = invitationOf($tenant, 'vieja@empresa-ejemplo.com', Role::Agent, hoursUntilExpiry: -1);
        $live = invitationOf($tenant, 'viva@empresa-ejemplo.com', Role::Agent);

        // La viva ocupa el único puesto libre: reabrir la caducada no cabe.
        $this->actingAs($admin)->post('/settings/team/invitations/'.$expired->id.'/resend')->assertSessionHasErrors('limit');
        $this->actingAs($admin)->post('/settings/team/invitations/'.$live->id.'/resend')->assertSessionHasNoErrors();
    });

    it('responde en JSON con 422 y el límite cuando se pide así', function (): void {
        $tenant = seatTenant();
        addMember($tenant, Role::Agent);

        $this->actingAs(adminOf($tenant))->postJson('/settings/team/invitations', invitePayload(['nueva@empresa-ejemplo.com']))
            ->assertStatus(422)->assertJsonPath('limit', 'agents')->assertJsonStructure(['message']);
    });
});

describe('cambiar un rol y reactivar', function (): void {
    it('pasar de observador a agente ocupa un puesto y se bloquea si no hay', function (): void {
        $tenant = seatTenant();
        addMember($tenant, Role::Agent);
        $observer = addMember($tenant, Role::Observer);

        $this->actingAs(adminOf($tenant))->put('/settings/team/members/'.$observer->id.'/role', ['role' => 'agent'])->assertSessionHasErrors('limit');

        expect($observer->fresh()?->role)->toBe(Role::Observer);
    });

    it('con un puesto libre sí deja, y a administrador también cuenta', function (): void {
        $tenant = seatTenant('equipo');
        $observer = addMember($tenant, Role::Observer);

        $this->actingAs(adminOf($tenant))->put('/settings/team/members/'.$observer->id.'/role', ['role' => 'admin'])->assertSessionHasNoErrors();

        expect($observer->fresh()?->role)->toBe(Role::Admin)->and(seatsUsed($tenant))->toBe(2);
    });

    it('bajar de agente a observador y cambiar entre roles con puesto siempre se puede', function (): void {
        $tenant = seatTenant();
        $agent = addMember($tenant, Role::Agent);
        $admin = adminOf($tenant);

        $this->actingAs($admin)->put('/settings/team/members/'.$agent->id.'/role', ['role' => 'admin'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->put('/settings/team/members/'.$agent->id.'/role', ['role' => 'observer'])->assertSessionHasNoErrors();

        expect(seatsUsed($tenant))->toBe(1);
    });

    it('cambiar el rol de un miembro desactivado no ocupa puesto', function (): void {
        $tenant = seatTenant();
        addMember($tenant, Role::Agent);
        $disabled = addMember($tenant, Role::Observer, disabled: true);

        $this->actingAs(adminOf($tenant))->put('/settings/team/members/'.$disabled->id.'/role', ['role' => 'agent'])->assertSessionHasNoErrors();
    });

    it('reactivar a un agente pide puesto; desactivar lo libera; reactivar a un observador no pide', function (): void {
        $tenant = seatTenant();
        $agent = addMember($tenant, Role::Agent, disabled: true);
        $other = addMember($tenant, Role::Agent);
        $observer = addMember($tenant, Role::Observer, disabled: true);
        $admin = adminOf($tenant);

        $this->actingAs($admin)->post('/settings/team/members/'.$agent->id.'/activate')->assertSessionHasErrors('limit');
        $this->actingAs($admin)->post('/settings/team/members/'.$observer->id.'/activate')->assertSessionHasNoErrors();

        $this->actingAs($admin)->post('/settings/team/members/'.$other->id.'/deactivate')->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/settings/team/members/'.$agent->id.'/activate')->assertSessionHasNoErrors();

        expect($agent->fresh()?->disabled_at)->toBeNull();
    });
});

describe('aceptar una invitación', function (): void {
    it('con el plan lleno la persona invitada entra igual: su invitación ya reservaba el puesto', function (): void {
        $tenant = seatTenant();
        $this->actingAs(adminOf($tenant))->post('/settings/team/invitations', invitePayload(['nueva@empresa-ejemplo.com']))->assertSessionHasNoErrors();
        $token = limitsToken('nueva@empresa-ejemplo.com');
        auth()->logout();

        expect(seatsUsed($tenant))->toBe(2);

        $password = str_repeat('Ac', 6).'3';
        $this->post('/invitations/'.$token, ['name' => 'Luis', 'password' => $password, 'password_confirmation' => $password])->assertRedirect(route('account'));

        expect(seatsUsed($tenant))->toBe(2)->and(User::query()->where('email', 'nueva@empresa-ejemplo.com')->exists())->toBeTrue();
    });

    it('si el plan bajó y la empresa está por encima del tope, no se aceptan más puestos, y se le dice sin detalles', function (): void {
        $tenant = seatTenant();
        $this->actingAs(adminOf($tenant))->post('/settings/team/invitations', invitePayload(['nueva@empresa-ejemplo.com']))->assertSessionHasNoErrors();
        $token = limitsToken('nueva@empresa-ejemplo.com');
        auth()->logout();

        Plan::query()->where('slug', 'inicial')->update(['max_agents' => 1]);

        $password = str_repeat('Ac', 6).'3';
        $this->post('/invitations/'.$token, ['name' => 'Luis', 'password' => $password, 'password_confirmation' => $password])
            ->assertSessionHasErrors(['token' => 'Esta empresa ya alcanzó el límite de personas de su plan. Pide al administrador de tu empresa que lo revise.']);

        expect(User::query()->where('email', 'nueva@empresa-ejemplo.com')->exists())->toBeFalse()
            ->and(session('errors')?->first('token'))->not->toContain('Inicial');
    });

    it('un observador invitado entra aunque la empresa esté por encima del tope', function (): void {
        $tenant = seatTenant();
        $this->actingAs(adminOf($tenant))->post('/settings/team/invitations', invitePayload(['mira@empresa-ejemplo.com'], 'observer'))->assertSessionHasNoErrors();
        $token = limitsToken('mira@empresa-ejemplo.com');
        auth()->logout();
        addMember($tenant, Role::Agent);
        Plan::query()->where('slug', 'inicial')->update(['max_agents' => 1]);

        $password = str_repeat('Ac', 6).'3';
        $this->post('/invitations/'.$token, ['name' => 'Mira', 'password' => $password, 'password_confirmation' => $password])->assertRedirect(route('account'));
    });
});

describe('plan y aislamiento', function (): void {
    it('cambiar de plan cambia los límites al instante', function (): void {
        $tenant = seatTenant();
        addMember($tenant, Role::Agent);
        $admin = adminOf($tenant);

        $this->actingAs($admin)->post('/settings/team/invitations', invitePayload(['uno@empresa-ejemplo.com']))->assertSessionHasErrors('limit');

        $this->artisan('plans:assign', ['tenant' => $tenant->id, 'plan' => 'equipo', '--no-interaction' => true])->assertSuccessful();

        $this->actingAs($admin)->post('/settings/team/invitations', invitePayload(['uno@empresa-ejemplo.com']))->assertSessionHasNoErrors();

        $this->artisan('plans:assign', ['tenant' => $tenant->id, 'plan' => 'inicial', '--no-interaction' => true])->assertSuccessful();

        $this->actingAs($admin)->post('/settings/team/invitations', invitePayload(['dos@empresa-ejemplo.com']))->assertSessionHasErrors('limit');
    });

    it('bajar de plan no echa a nadie: se conserva lo que hay y solo se bloquea añadir', function (): void {
        $tenant = seatTenant('negocio');
        for ($i = 0; $i < 4; $i++) {
            addMember($tenant, Role::Agent);
        }

        $this->artisan('plans:assign', ['tenant' => $tenant->id, 'plan' => 'inicial', '--no-interaction' => true])->assertSuccessful();

        $tenant->refresh();
        $limits = app(PlanLimits::class);

        expect(seatsUsed($tenant))->toBe(5)->and($limits->isOver($tenant, Limit::Agents))->toBeTrue()
            ->and(User::query()->inTenant($tenant->id)->whereNull('disabled_at')->count())->toBe(5);

        $this->actingAs(adminOf($tenant))->post('/settings/team/invitations', invitePayload(['nueva@empresa-ejemplo.com']))->assertSessionHasErrors('limit');
    });

    it('el plan lleno de una empresa no afecta a otra', function (): void {
        $full = seatTenant();
        addMember($full, Role::Agent);
        $free = seatTenant();

        $this->actingAs(adminOf($full))->post('/settings/team/invitations', invitePayload(['a@empresa-ejemplo.com']))->assertSessionHasErrors('limit');
        $this->actingAs(adminOf($free))->post('/settings/team/invitations', invitePayload(['b@empresa-ejemplo.com']))->assertSessionHasNoErrors();
    });

    it('el cliente no puede alterar límites ni plan con campos extra', function (): void {
        $tenant = seatTenant();
        addMember($tenant, Role::Agent);
        $negocio = Plan::query()->where('slug', 'negocio')->firstOrFail();

        $this->actingAs(adminOf($tenant))->post('/settings/team/invitations', [...invitePayload(['nueva@empresa-ejemplo.com']), 'plan_id' => $negocio->id, 'max_agents' => 99, 'limit' => 99, 'seats' => 99])
            ->assertSessionHasErrors('limit');

        expect($tenant->fresh()?->plan_id)->toBe(Plan::default()->id);
    });

    it('la pantalla de equipo entrega los puestos usados, el tope y el plan', function (): void {
        $tenant = seatTenant();
        invitationOf($tenant, 'pendiente@empresa-ejemplo.com', Role::Agent);

        $this->actingAs(adminOf($tenant))->get('/settings/team')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('seats.plan', 'Inicial')->where('seats.used', 2)->where('seats.max', 2)->where('seats.remaining', 0)->where('seats.over', false));

        Plan::query()->where('slug', 'inicial')->update(['max_agents' => null]);

        $this->actingAs(adminOf($tenant))->get('/settings/team')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('seats.max', null)->where('seats.remaining', null));
    });
});
