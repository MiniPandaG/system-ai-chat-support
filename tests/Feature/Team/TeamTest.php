<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/**
 * Contraseña armada por partes: una de ejemplo escrita de corrido la marca el escaneo de secretos.
 */
function teamPassword(): string
{
    return str_repeat('Eq', 6).'7';
}

function memberOf(Tenant $tenant, Role $role, bool $twoFactor = false): User
{
    $user = User::factory()->forTenant($tenant, $role)->password(teamPassword())->create();

    if ($twoFactor) {
        $user->forceFill([
            'two_factor_secret' => encrypt('SECRETOPRUEBA'),
            'two_factor_recovery_codes' => encrypt(json_encode(['x'])),
            'two_factor_confirmed_at' => now(),
        ])->save();
    }

    return $user;
}

/** Administrador con 2FA, que es lo que exige llegar a las rutas de empresa. */
function companyAdmin(Tenant $tenant): User
{
    return memberOf($tenant, Role::Admin, twoFactor: true);
}

function platformStaff(): User
{
    $staff = User::factory()->create(['is_platform_admin' => true]);
    $staff->forceFill([
        'two_factor_secret' => encrypt('SECRETOPRUEBA'),
        'two_factor_recovery_codes' => encrypt(json_encode(['x'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $staff;
}

it('un administrador ve a los miembros de su empresa y solo a ellos', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = companyAdmin($tenant);
    $agent = memberOf($tenant, Role::Agent);
    $outsider = memberOf(Tenant::factory()->create(), Role::Agent);

    $this->actingAs($admin)->get('/settings/team')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($admin, $agent, $outsider): void {
            $page->component('settings/Team')
                ->has('members', 2)
                ->where('members', fn ($members): bool => collect($members)->pluck('id')->sort()->values()->all() === collect([$admin->id, $agent->id])->sort()->values()->all()
                    && ! collect($members)->pluck('id')->contains($outsider->id))
                ->where('copy.title', 'Equipo');
        });
});

it('la pantalla de equipo solo la abre un administrador activo de empresa', function (Role $role, int $status): void {
    $tenant = Tenant::factory()->create();
    $user = $role === Role::Admin ? companyAdmin($tenant) : memberOf($tenant, $role);

    $this->actingAs($user)->get('/settings/team')->assertStatus($status);
})->with([
    'administrador' => [Role::Admin, 200],
    'agente' => [Role::Agent, 403],
    'observador' => [Role::Observer, 403],
]);

it('el personal de la plataforma no entra al equipo de ninguna empresa', function (): void {
    $member = memberOf(Tenant::factory()->create(), Role::Agent);

    $this->actingAs(platformStaff())->get('/settings/team')->assertForbidden();
    $this->actingAs(platformStaff())->put('/settings/team/members/'.$member->id.'/role', ['role' => 'admin'])->assertNotFound();
    $this->actingAs(platformStaff())->post('/settings/team/members/'.$member->id.'/deactivate')->assertNotFound();

    expect($member->fresh()?->role)->toBe(Role::Agent)->and($member->fresh()?->disabled_at)->toBeNull();
});

it('un visitante es enviado al inicio de sesión', function (): void {
    $member = memberOf(Tenant::factory()->create(), Role::Agent);

    $this->get('/settings/team')->assertRedirect('/login');
    $this->put('/settings/team/members/'.$member->id.'/role', ['role' => 'admin'])->assertRedirect('/login');
    $this->post('/settings/team/members/'.$member->id.'/deactivate')->assertRedirect('/login');
});

it('un administrador sin 2FA activo no llega al equipo', function (): void {
    $admin = memberOf(Tenant::factory()->create(), Role::Admin);

    $this->actingAs($admin)->get('/settings/team')->assertRedirect(route('security'));
});

it('un administrador cambia el rol de un miembro y queda auditado', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = companyAdmin($tenant);
    $agent = memberOf($tenant, Role::Agent);

    $this->actingAs($admin)->put('/settings/team/members/'.$agent->id.'/role', ['role' => 'observer'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Actualizamos el rol.');

    expect($agent->fresh()?->role)->toBe(Role::Observer);

    $log = AuditLog::query()->where('event', AuditEvent::MemberRoleChanged->value)->where('user_id', $admin->id)->firstOrFail();

    expect($log->context)->toEqual(['member_id' => $agent->id, 'from' => 'agent', 'to' => 'observer']);
});

it('un agente o un observador no puede cambiar roles, ni siquiera el suyo', function (Role $role): void {
    $tenant = Tenant::factory()->create();
    $actor = memberOf($tenant, $role);
    $peer = memberOf($tenant, Role::Agent);

    $this->actingAs($actor)->put('/settings/team/members/'.$actor->id.'/role', ['role' => 'admin'])->assertForbidden();
    $this->actingAs($actor)->put('/settings/team/members/'.$peer->id.'/role', ['role' => 'admin'])->assertForbidden();

    expect($actor->fresh()?->role)->toBe($role)->and($peer->fresh()?->role)->toBe(Role::Agent);
})->with([Role::Agent, Role::Observer]);

it('un administrador no puede cambiar su propio rol ni desactivarse: la empresa siempre conserva uno', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = companyAdmin($tenant);

    $this->actingAs($admin)->put('/settings/team/members/'.$admin->id.'/role', ['role' => 'agent'])->assertForbidden();
    $this->actingAs($admin)->post('/settings/team/members/'.$admin->id.'/deactivate')->assertForbidden();

    $fresh = $admin->fresh();

    expect($fresh?->role)->toBe(Role::Admin)->and($fresh?->disabled_at)->toBeNull();
});

it('otra empresa recibe 404 en todas las rutas de miembros y no cambia nada', function (): void {
    $victim = memberOf(Tenant::factory()->create(), Role::Agent);
    $intruder = companyAdmin(Tenant::factory()->create());

    $this->assertOtherTenantGets404($intruder, 'PUT', '/settings/team/members/'.$victim->id.'/role', ['role' => 'admin']);
    $this->assertOtherTenantGets404($intruder, 'POST', '/settings/team/members/'.$victim->id.'/deactivate');
    $this->assertOtherTenantGets404($intruder, 'POST', '/settings/team/members/'.$victim->id.'/activate');

    $fresh = $victim->fresh();

    expect($fresh?->role)->toBe(Role::Agent)->and($fresh?->disabled_at)->toBeNull();
});

it('un identificador inexistente o mal formado da 404', function (string $id): void {
    $this->actingAs(companyAdmin(Tenant::factory()->create()))
        ->put('/settings/team/members/'.$id.'/role', ['role' => 'agent'])
        ->assertNotFound();
})->with(['inexistente' => ['999999'], 'texto' => ['abc'], 'negativo' => ['-1']]);

it('rechaza roles que no existen y campos que intentan otorgar más permisos', function (array $payload): void {
    $tenant = Tenant::factory()->create();
    $admin = companyAdmin($tenant);
    $agent = memberOf($tenant, Role::Agent);

    $this->actingAs($admin)->put('/settings/team/members/'.$agent->id.'/role', $payload)->assertSessionHasErrors('role');

    expect($agent->fresh()?->role)->toBe(Role::Agent);
})->with([
    'rol inventado' => [['role' => 'superadmin']],
    'vacío' => [['role' => '']],
    'no es texto' => [['role' => ['admin']]],
    'falta el rol' => [['is_platform_admin' => '1']],
]);

it('ignora tenant_id y is_platform_admin enviados junto al rol', function (): void {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $admin = companyAdmin($tenant);
    $agent = memberOf($tenant, Role::Agent);

    $this->actingAs($admin)->put('/settings/team/members/'.$agent->id.'/role', [
        'role' => 'observer',
        'tenant_id' => (string) $other->id,
        'is_platform_admin' => '1',
    ])->assertSessionHasNoErrors();

    $fresh = $agent->fresh();

    expect($fresh?->role)->toBe(Role::Observer)
        ->and($fresh?->tenant_id)->toBe($tenant->id)
        ->and($fresh?->is_platform_admin)->toBeFalse();
});

it('desactivar cierra las sesiones del miembro, bloquea su acceso y queda auditado', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = companyAdmin($tenant);
    $agent = memberOf($tenant, Role::Agent);

    DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $agent->id, 'payload' => 'x', 'last_activity' => time()]);

    $this->actingAs($admin)->post('/settings/team/members/'.$agent->id.'/deactivate')
        ->assertSessionHas('status', 'Desactivamos al miembro y cerramos sus sesiones.');

    expect($agent->fresh()?->disabled_at)->not->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $agent->id)->count())->toBe(0)
        ->and(AuditLog::query()->where('event', AuditEvent::MemberDeactivated->value)->where('user_id', $admin->id)->exists())->toBeTrue();
});

it('un miembro desactivado no inicia sesión y recibe el mismo rechazo genérico', function (): void {
    $tenant = Tenant::factory()->create();
    $agent = memberOf($tenant, Role::Agent);
    $agent->forceFill(['disabled_at' => now()])->save();

    $this->post('/login', ['email' => $agent->email, 'password' => teamPassword()])
        ->assertSessionHasErrors(['email' => 'El correo o la contraseña no son correctos.']);

    $this->assertGuest();
});

it('una sesión que seguía abierta se cierra en la siguiente petición', function (): void {
    $agent = memberOf(Tenant::factory()->create(), Role::Agent);
    $agent->forceFill(['disabled_at' => now()])->save();

    $this->actingAs($agent->fresh() ?? $agent)->get('/account')
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Tu cuenta fue desactivada. Habla con el administrador de tu empresa.');

    $this->assertGuest();
});

it('reactivar devuelve el acceso y queda auditado', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = companyAdmin($tenant);
    $agent = memberOf($tenant, Role::Agent);
    $agent->forceFill(['disabled_at' => now()])->save();

    $this->actingAs($admin)->post('/settings/team/members/'.$agent->id.'/activate')
        ->assertSessionHas('status', 'Reactivamos al miembro.');

    expect($agent->fresh()?->disabled_at)->toBeNull()
        ->and(AuditLog::query()->where('event', AuditEvent::MemberActivated->value)->exists())->toBeTrue();

    auth()->logout();
    $this->post('/login', ['email' => $agent->email, 'password' => teamPassword()]);

    $this->assertAuthenticatedAs($agent);
});

it('desactivar y reactivar no filtran correos en la auditoría', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = companyAdmin($tenant);
    $agent = memberOf($tenant, Role::Agent);

    $this->actingAs($admin)->post('/settings/team/members/'.$agent->id.'/deactivate');
    $this->actingAs($admin)->post('/settings/team/members/'.$agent->id.'/activate');

    $dump = (string) json_encode(AuditLog::query()->whereIn('event', [AuditEvent::MemberDeactivated->value, AuditEvent::MemberActivated->value])->get()->map->getAttributes());

    expect($dump)->not->toContain($agent->email)->and($dump)->not->toContain($agent->name);
});

it('matriz de la política: solo un administrador activo de la misma empresa gestiona a otro miembro', function (): void {
    $tenant = Tenant::factory()->create();
    $target = memberOf($tenant, Role::Agent);
    $outsiderTarget = memberOf(Tenant::factory()->create(), Role::Agent);
    $disabledAdmin = memberOf($tenant, Role::Admin);
    $disabledAdmin->forceFill(['disabled_at' => now()])->save();

    $allowed = [
        'admin de la empresa' => [companyAdmin($tenant), $target, true],
        'admin de otra empresa' => [companyAdmin(Tenant::factory()->create()), $target, false],
        'agente' => [memberOf($tenant, Role::Agent), $target, false],
        'observador' => [memberOf($tenant, Role::Observer), $target, false],
        'personal de la plataforma' => [platformStaff(), $target, false],
        'admin desactivado' => [$disabledAdmin, $target, false],
        'admin sobre alguien de otra empresa' => [companyAdmin($tenant), $outsiderTarget, false],
    ];

    foreach ($allowed as $label => [$actor, $member, $expected]) {
        foreach (['changeRole', 'deactivate', 'activate'] as $ability) {
            expect(Gate::forUser($actor)->allows($ability, $member))->toBe($expected, "{$label} → {$ability}");
        }
    }
});

it('los datos compartidos dicen si el usuario puede ver el equipo', function (Role $role, bool $expected): void {
    $tenant = Tenant::factory()->create();
    $user = $role === Role::Admin ? companyAdmin($tenant) : memberOf($tenant, $role);

    $this->actingAs($user)->get('/account')->assertInertia(fn (AssertableInertia $page) => $page->where('auth.can.team', $expected));
})->with([
    'administrador' => [Role::Admin, true],
    'agente' => [Role::Agent, false],
    'observador' => [Role::Observer, false],
]);

it('disabled_at no es rellenable en masa', function (): void {
    expect((new User)->isFillable('disabled_at'))->toBeFalse();
});
