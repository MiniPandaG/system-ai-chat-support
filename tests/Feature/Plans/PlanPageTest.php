<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Plans\Limit;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

function planPageAdmin(string $plan = 'inicial', ?Tenant $tenant = null): User
{
    $admin = User::factory()->forTenant($tenant ?? Tenant::factory()->onPlan($plan)->create(), Role::Admin)->create();
    $admin->forceFill([
        'two_factor_secret' => encrypt('SECRETOPRUEBA'),
        'two_factor_recovery_codes' => encrypt(json_encode(['x'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $admin;
}

function planPageMember(Tenant $tenant, Role $role, bool $disabled = false): User
{
    $user = User::factory()->forTenant($tenant, $role)->create();

    if ($disabled) {
        $user->forceFill(['disabled_at' => now()])->save();
    }

    return $user;
}

it('un administrador ve su plan con cada límite, su tope y su uso', function (): void {
    $admin = planPageAdmin('equipo');

    $this->actingAs($admin)->get('/settings/plan')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('settings/Plan')
        ->where('copy.title', 'Plan')
        ->where('plan.name', 'Equipo')
        ->has('limits', 4)
        ->where('limits.0', ['key' => 'agents', 'monthly' => false, 'measured' => true, 'limit' => 5, 'used' => 1, 'remaining' => 4, 'over' => false])
        ->where('limits.1.key', 'channels')->where('limits.1.limit', 3)->where('limits.1.measured', true)->where('limits.1.used', 0)
        ->where('limits.2.key', 'tickets_per_month')->where('limits.2.limit', 1000)->where('limits.2.monthly', true)->where('limits.2.measured', true)->where('limits.2.used', 0)
        ->where('limits.3.key', 'ai_credits')->where('limits.3.limit', 500)->where('limits.3.monthly', true));
});

it('cada plan muestra sus propios topes', function (string $plan, array $expected): void {
    $admin = planPageAdmin($plan);

    $this->actingAs($admin)->get('/settings/plan')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('plan.name', ucfirst($plan))
        ->where('limits', fn ($limits): bool => collect($limits)->pluck('limit')->all() === $expected));
})->with([
    'inicial' => ['inicial', [2, 1, 100, 50]],
    'equipo' => ['equipo', [5, 3, 1000, 500]],
    'negocio' => ['negocio', [15, 5, 5000, 2500]],
]);

it('cambiar de plan se refleja en la pantalla al instante', function (): void {
    $admin = planPageAdmin('inicial');
    $tenantId = $admin->tenant_id;

    $this->actingAs($admin)->get('/settings/plan')->assertInertia(fn (AssertableInertia $page) => $page->where('plan.name', 'Inicial')->where('limits.0.limit', 2));

    $this->artisan('plans:assign', ['tenant' => $tenantId, 'plan' => 'negocio', '--no-interaction' => true])->assertSuccessful();

    $this->actingAs($admin)->get('/settings/plan')->assertInertia(fn (AssertableInertia $page) => $page->where('plan.name', 'Negocio')->where('limits.0.limit', 15));
});

it('el uso de puestos suma miembros activos con puesto e invitaciones abiertas, y lo desglosa', function (): void {
    $admin = planPageAdmin('negocio');
    $tenant = $admin->tenant()->firstOrFail();
    planPageMember($tenant, Role::Agent);
    planPageMember($tenant, Role::Agent);
    planPageMember($tenant, Role::Observer);
    planPageMember($tenant, Role::Agent, disabled: true);

    DB::table('invitations')->insert([
        ['tenant_id' => $tenant->id, 'email' => 'a@empresa-ejemplo.com', 'role' => 'agent', 'token_hash' => str_repeat('a', 64), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()],
        ['tenant_id' => $tenant->id, 'email' => 'b@empresa-ejemplo.com', 'role' => 'observer', 'token_hash' => str_repeat('b', 64), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()],
        ['tenant_id' => $tenant->id, 'email' => 'c@empresa-ejemplo.com', 'role' => 'agent', 'token_hash' => str_repeat('c', 64), 'expires_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->actingAs($admin)->get('/settings/plan')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('limits.0.used', 4)->where('limits.0.remaining', 11)
        ->where('seatsDetail', '3 miembros activos y 1 invitación pendiente.'));
});

it('el desglose usa singular y plural correctos', function (int $agents, int $invitations, string $expected): void {
    $admin = planPageAdmin('negocio');
    $tenant = $admin->tenant()->firstOrFail();

    for ($i = 1; $i < $agents; $i++) {
        planPageMember($tenant, Role::Agent);
    }

    for ($i = 0; $i < $invitations; $i++) {
        DB::table('invitations')->insert(['tenant_id' => $tenant->id, 'email' => "i{$i}@empresa-ejemplo.com", 'role' => 'agent', 'token_hash' => str_pad((string) $i, 64, 'f'), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
    }

    $this->actingAs($admin)->get('/settings/plan')->assertInertia(fn (AssertableInertia $page) => $page->where('seatsDetail', $expected));
})->with([
    'solo el administrador' => [1, 0, '1 miembro activo y sin invitaciones pendientes.'],
    'varios y varias' => [3, 2, '3 miembros activos y 2 invitaciones pendientes.'],
]);

it('marca que se pasó el límite tras bajar de plan, sin quitar a nadie', function (): void {
    $admin = planPageAdmin('negocio');
    $tenant = $admin->tenant()->firstOrFail();
    for ($i = 0; $i < 3; $i++) {
        planPageMember($tenant, Role::Agent);
    }

    $this->artisan('plans:assign', ['tenant' => $tenant->id, 'plan' => 'inicial', '--no-interaction' => true])->assertSuccessful();

    $this->actingAs($admin)->get('/settings/plan')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('limits.0.used', 4)->where('limits.0.limit', 2)->where('limits.0.over', true)->where('limits.0.remaining', 0));
});

it('un límite sin tope se entrega como nulo', function (): void {
    Plan::query()->where('slug', 'inicial')->update(['max_agents' => null, 'ai_credits_per_month' => null]);

    $this->actingAs(planPageAdmin('inicial'))->get('/settings/plan')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('limits.0.limit', null)->where('limits.0.remaining', null)->where('limits.0.over', false)->where('limits.3.limit', null));
});

it('solo un administrador activo de empresa entra', function (Role $role): void {
    $user = User::factory()->forTenant(Tenant::factory()->create(), $role)->create();

    $this->actingAs($user)->get('/settings/plan')->assertForbidden();
})->with([Role::Agent, Role::Observer]);

it('el personal de la plataforma, un visitante y un administrador sin 2FA no entran', function (): void {
    $this->get('/settings/plan')->assertRedirect('/login');

    $staff = User::factory()->create(['is_platform_admin' => true]);
    $staff->forceFill(['two_factor_secret' => encrypt('S'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()])->save();
    $this->actingAs($staff)->get('/settings/plan')->assertForbidden();

    $noTwoFactor = User::factory()->forTenant(Tenant::factory()->create(), Role::Admin)->create();
    $this->actingAs($noTwoFactor)->get('/settings/plan')->assertRedirect(route('security'));
});

it('un administrador desactivado no entra', function (): void {
    $admin = planPageAdmin();
    $admin->forceFill(['disabled_at' => now()])->save();

    $this->actingAs($admin->fresh() ?? $admin)->get('/settings/plan')->assertRedirect(route('login'));
});

it('cada empresa ve solo su plan y su uso', function (): void {
    $a = planPageAdmin('inicial');
    $b = planPageAdmin('negocio');
    planPageMember($b->tenant()->firstOrFail(), Role::Agent);
    planPageMember($b->tenant()->firstOrFail(), Role::Agent);

    $this->actingAs($a)->get('/settings/plan')->assertInertia(fn (AssertableInertia $page) => $page->where('plan.name', 'Inicial')->where('limits.0.used', 1));
    $this->actingAs($b)->get('/settings/plan')->assertInertia(fn (AssertableInertia $page) => $page->where('plan.name', 'Negocio')->where('limits.0.used', 3));
});

it('la pantalla no acepta ni usa nada del cliente', function (): void {
    $admin = planPageAdmin('inicial');
    $other = planPageAdmin('negocio');

    $this->actingAs($admin)->get('/settings/plan?plan=negocio&tenant_id='.$other->tenant_id.'&limit=99')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('plan.name', 'Inicial')->where('limits.0.limit', 2));

    foreach (['post', 'put', 'patch', 'delete'] as $method) {
        $this->actingAs($admin)->{$method}('/settings/plan', ['plan_id' => 3])->assertStatus(405);
    }
});

it('los datos compartidos dicen si el usuario ve el plan', function (Role $role, bool $expected): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant, $role)->create();

    if ($role === Role::Admin) {
        $user->forceFill(['two_factor_secret' => encrypt('S'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()])->save();
    }

    $this->actingAs($user)->get('/account')->assertInertia(fn (AssertableInertia $page) => $page->where('auth.can.plan', $expected));
})->with([
    'administrador' => [Role::Admin, true],
    'agente' => [Role::Agent, false],
    'observador' => [Role::Observer, false],
]);

it('los textos de la pantalla están en español para cada límite', function (): void {
    $copy = __('plans.page');

    foreach (Limit::cases() as $limit) {
        expect($copy['labels'][$limit->value])->toBeString()->and($copy['help'][$limit->value])->toBeString();
    }

    expect($copy['over'])->toContain('Superas el límite');
});

it('una invitación de otra empresa no cuenta en el uso', function (): void {
    $a = planPageAdmin('negocio');
    $b = planPageAdmin('negocio');
    DB::table('invitations')->insert(['tenant_id' => $b->tenant_id, 'email' => 'x@empresa-ejemplo.com', 'role' => 'agent', 'token_hash' => str_repeat('d', 64), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($a)->get('/settings/plan')->assertInertia(fn (AssertableInertia $page) => $page->where('limits.0.used', 1));

});
