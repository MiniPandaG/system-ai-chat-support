<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Models\User;
use App\Tenancy\TenantContext;
use Inertia\Testing\AssertableInertia;

function resetAdmin(?Tenant $tenant = null): User
{
    $admin = User::factory()->forTenant($tenant ?? Tenant::factory()->create(), Role::Admin)->create();
    $admin->forceFill([
        'two_factor_secret' => encrypt('SECRETOPRUEBA'),
        'two_factor_recovery_codes' => encrypt(json_encode(['x'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $admin;
}

function brandingFor(Tenant $tenant): TenantBranding
{
    return app(TenantContext::class)->run($tenant, fn (): TenantBranding => TenantBranding::current()->refresh());
}

it('restaurar devuelve la paleta a los valores de fábrica y deja sus textos calculados', function (): void {
    $admin = resetAdmin();
    $tenant = $admin->tenant()->firstOrFail();
    $this->actingAs($admin)->put('/settings/branding/colors', ['colors' => ['primary' => '#2164DA', 'accent' => '#D9540F']])->assertSessionHasNoErrors();
    expect(brandingFor($tenant)->colors)->not->toBeNull();

    $this->actingAs($admin)->delete('/settings/branding/colors')
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Restauramos la paleta por defecto.');

    $branding = brandingFor($tenant);

    expect($branding->colors)->toBeNull()
        ->and($branding->foregrounds)->toHaveCount(7)
        ->and($branding->foregrounds['primary'])->toBe('#1D1208')
        ->and($branding->panelVariables())->toBe([])
        ->and(AuditLog::query()->where('event', AuditEvent::BrandingPaletteReset->value)->where('user_id', $admin->id)->exists())->toBeTrue();

    $this->actingAs($admin)->get('/account')->assertInertia(fn (AssertableInertia $page) => $page->where('auth.tenant.theme', null));
    expect($this->actingAs($admin)->get('/account')->getContent())->not->toContain(':root{--color-');
});

it('restaurar no toca el logo ni el nombre', function (): void {
    $admin = resetAdmin();
    $tenant = $admin->tenant()->firstOrFail();
    $name = $tenant->name;
    $this->actingAs($admin)->put('/settings/branding/colors', ['colors' => ['primary' => '#2164DA']]);

    $this->actingAs($admin)->delete('/settings/branding/colors');

    expect($tenant->fresh()?->name)->toBe($name);
});

it('restaurar sin cambios previos no falla', function (): void {
    $this->actingAs(resetAdmin())->delete('/settings/branding/colors')->assertSessionHasNoErrors();
});

it('solo un administrador activo de la empresa restaura', function (Role $role): void {
    $user = User::factory()->forTenant(Tenant::factory()->create(), $role)->create();

    $this->actingAs($user)->delete('/settings/branding/colors')->assertForbidden();
})->with([Role::Agent, Role::Observer]);

it('un visitante no restaura', function (): void {
    $this->delete('/settings/branding/colors')->assertRedirect('/login');
});

it('restaurar una empresa no afecta a la paleta de otra', function (): void {
    $a = resetAdmin();
    $b = resetAdmin();
    $this->actingAs($a)->put('/settings/branding/colors', ['colors' => ['primary' => '#2164DA']]);
    $this->actingAs($b)->put('/settings/branding/colors', ['colors' => ['primary' => '#D9540F']]);

    $this->actingAs($a)->delete('/settings/branding/colors');

    expect(brandingFor($b->tenant()->firstOrFail())->colors)->toBe(['primary' => '#D9540F'])
        ->and(brandingFor($a->tenant()->firstOrFail())->colors)->toBeNull();
});

it('la pantalla entrega los textos calculados de la paleta efectiva para la vista previa', function (): void {
    $admin = resetAdmin();
    $this->actingAs($admin)->put('/settings/branding/colors', ['colors' => ['primary' => '#2164DA']]);

    $this->actingAs($admin)->get('/settings/branding')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('palette.foregrounds.primary', '#F6F1EA')
        ->where('palette.foregrounds.secondary', '#F6F1EA')
        ->has('palette.foregrounds', 7));
});

it('el ámbito de marca de la interfaz usa las mismas fórmulas que tokens.css', function (): void {
    $tokens = (string) file_get_contents(base_path('resources/tokens/tokens.css'));
    $scope = (string) file_get_contents(base_path('resources/css/brand-scope.css'));
    $base = ['primary', 'secondary', 'accent', 'background', 'surface', 'text', 'success', 'warning', 'danger', 'info'];

    $declarations = function (string $selector, string $css): array {
        preg_match('/^'.preg_quote($selector, '/').'\s*\{(.*?)\n\}/ms', $css, $block);
        preg_match_all('/^\s*(--color-[a-z0-9-]+):\s*([^;]+);/m', $block[1] ?? '', $found, PREG_SET_ORDER);

        return array_column($found, 2, 1);
    };

    $dark = array_filter($declarations(':root', $tokens), fn (string $value): bool => str_contains($value, 'var(') || str_contains($value, 'color-mix('));
    $light = array_filter($declarations(':root[data-theme="claro"]', $tokens), fn (string $name): bool => ! in_array(substr($name, 8), $base, true), ARRAY_FILTER_USE_KEY);

    expect($dark)->not->toBeEmpty()->and($light)->not->toBeEmpty();
    expect($declarations('[data-brand-scope]', $scope))->toEqual(array_map('trim', $dark))
        ->and($declarations("[data-brand-scope][data-mode='claro']", $scope))->toEqual(array_map('trim', $light));
});
