<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

function tenantMember(Role $role, bool $withTwoFactor = false): User
{
    $user = User::factory()->forTenant(Tenant::factory()->create(), $role)->create();

    if ($withTwoFactor) {
        $user->forceFill([
            'two_factor_secret' => encrypt('SECRETOPRUEBA'),
            'two_factor_recovery_codes' => encrypt(json_encode(['x'])),
            'two_factor_confirmed_at' => now(),
        ])->save();
    }

    return $user;
}

it('un administrador de empresa sin 2FA solo llega a la pantalla de seguridad', function (): void {
    $this->actingAs(tenantMember(Role::Admin))->get('/account')->assertRedirect(route('security'));
});

it('un administrador de empresa con 2FA activo entra a su cuenta', function (): void {
    $this->actingAs(tenantMember(Role::Admin, withTwoFactor: true))->get('/account')->assertOk();
});

it('un agente y un observador entran sin 2FA, porque para ellos es opcional', function (Role $role): void {
    $this->actingAs(tenantMember($role))->get('/account')->assertOk();
})->with([Role::Agent, Role::Observer]);

it('un administrador de empresa con 2FA activo no puede desactivarlo', function (): void {
    $admin = tenantMember(Role::Admin, withTwoFactor: true);

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete('/user/two-factor-authentication')
        ->assertSessionHasErrors(['two_factor' => 'La verificación en dos pasos es obligatoria para administradores y no se puede desactivar.']);

    expect($admin->fresh()?->hasEnabledTwoFactorAuthentication())->toBeTrue();
});

it('un agente sí puede desactivar su 2FA', function (): void {
    $agent = tenantMember(Role::Agent, withTwoFactor: true);

    $this->actingAs($agent)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete('/user/two-factor-authentication')
        ->assertSessionHasNoErrors();

    expect($agent->fresh()?->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

it('la pantalla de seguridad avisa a un administrador de empresa que el 2FA es obligatorio', function (): void {
    $this->actingAs(tenantMember(Role::Admin))
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get('/settings/security')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('twoFactor.required', true));
});

it('los datos compartidos incluyen la empresa del usuario, y ninguna para el personal de la plataforma', function (): void {
    $admin = tenantMember(Role::Admin, withTwoFactor: true);
    $staff = User::factory()->create(['is_platform_admin' => true]);

    $this->actingAs($admin)->get('/account')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('auth.tenant.name', $admin->tenant()->firstOrFail()->name)
        ->where('auth.user.role', 'admin')
        ->where('panel.account', 'Mi cuenta'));

    $this->actingAs($staff)->get('/settings/security')->assertRedirect(route('password.confirm'));
    $this->actingAs($staff)->withSession(['auth.password_confirmed_at' => time()])->get('/settings/security')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.tenant', null)->where('auth.user.role', null));
});
