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

function paletteAdmin(?Tenant $tenant = null): User
{
    $admin = User::factory()->forTenant($tenant ?? Tenant::factory()->create(), Role::Admin)->create();
    $admin->forceFill([
        'two_factor_secret' => encrypt('SECRETOPRUEBA'),
        'two_factor_recovery_codes' => encrypt(json_encode(['x'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $admin;
}

function savedBranding(Tenant $tenant): TenantBranding
{
    return app(TenantContext::class)->run($tenant, fn (): TenantBranding => TenantBranding::current()->refresh());
}

it('guarda una paleta válida con sus textos calculados y la audita', function (): void {
    $admin = paletteAdmin();
    $tenant = $admin->tenant()->firstOrFail();

    $this->actingAs($admin)->put('/settings/branding/colors', ['colors' => ['primary' => '#2164da', 'accent' => '#2BAFBF']])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Guardamos tu paleta.');

    $branding = savedBranding($tenant);

    expect($branding->colors)->toBe(['primary' => '#2164DA'])
        ->and($branding->foregrounds)->toHaveCount(7)
        ->and($branding->foregrounds['primary'])->toBe('#F6F1EA');

    $log = AuditLog::query()->where('event', AuditEvent::BrandingPaletteChanged->value)->firstOrFail();

    expect($log->context)->toBe(['changed' => 'primary'])->and(json_encode($log->getAttributes()))->not->toContain('#2164');
});

it('rechaza un color de bajo contraste con la explicación y el tono propuesto, y no guarda nada', function (): void {
    $admin = paletteAdmin();
    $tenant = $admin->tenant()->firstOrFail();

    $this->actingAs($admin)->put('/settings/branding/colors', ['colors' => ['primary' => '#2F6FE0']])
        ->assertSessionHasErrors('colors.primary');

    $message = session('errors')?->first('colors.primary');

    expect($message)->toContain('4,1:1')->toContain('mínimo 4,5:1')->toContain('Te proponemos #')
        ->and(savedBranding($tenant)->colors)->toBeNull();
});

it('rechaza formatos inválidos, claves fuera de la lista e intentos de CSS', function (array $colors): void {
    $admin = paletteAdmin();
    $tenant = $admin->tenant()->firstOrFail();

    $this->actingAs($admin)->put('/settings/branding/colors', ['colors' => $colors])->assertSessionHasErrors();

    expect(savedBranding($tenant)->colors)->toBeNull();
})->with([
    'nombre de color' => [['primary' => 'red']],
    'tres dígitos' => [['primary' => '#FFF']],
    'declaración inyectada' => [['primary' => '#F65F1E;--x:1']],
    'bloque inyectado' => [['primary' => 'red;}body{display:none']],
    'url()' => [['primary' => 'url(https://x.test/a.png)']],
    // Laravel recorta los saltos de línea de los extremos antes de validar; uno en medio sí llegaría y se rechaza.
    'salto de línea dentro' => [['primary' => "#F65F\n1E"]],
    'clave desconocida' => [['--evil' => '#000000']],
    'clave con CSS' => [['primary;background' => '#000000']],
    'valor en lista' => [['primary' => ['#F65F1E']]],
]);

it('exige el campo colors', function (): void {
    $this->actingAs(paletteAdmin())->put('/settings/branding/colors', [])->assertSessionHasErrors('colors');
});

it('una paleta vacía o igual a la de fábrica devuelve la marca a los valores por defecto', function (): void {
    $admin = paletteAdmin();
    $tenant = $admin->tenant()->firstOrFail();
    $this->actingAs($admin)->put('/settings/branding/colors', ['colors' => ['primary' => '#2164DA']]);

    $this->actingAs($admin)->put('/settings/branding/colors', ['colors' => ['primary' => '#F65F1E']])->assertSessionHasNoErrors();

    expect(savedBranding($tenant)->colors)->toBeNull();
});

it('solo un administrador activo de la empresa puede guardar o consultar la paleta', function (Role $role): void {
    $user = User::factory()->forTenant(Tenant::factory()->create(), $role)->create();

    $this->actingAs($user)->put('/settings/branding/colors', ['colors' => ['primary' => '#2164DA']])->assertForbidden();
    $this->actingAs($user)->postJson('/settings/branding/palette/check', ['colors' => ['primary' => '#2164DA']])->assertForbidden();
})->with([Role::Agent, Role::Observer]);

it('un visitante no puede consultar ni guardar', function (): void {
    $this->postJson('/settings/branding/palette/check', ['colors' => []])->assertUnauthorized();
    $this->put('/settings/branding/colors', ['colors' => []])->assertRedirect('/login');
});

it('la consulta devuelve colores efectivos, textos calculados y problemas, sin guardar', function (): void {
    $admin = paletteAdmin();
    $tenant = $admin->tenant()->firstOrFail();

    $this->actingAs($admin)->postJson('/settings/branding/palette/check', ['colors' => ['primary' => '#2F6FE0']])
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('colors.primary', '#2F6FE0')
        ->assertJsonPath('colors.secondary', '#2E5EAA')
        ->assertJsonPath('issues.0.key', 'primary')
        ->assertJsonPath('issues.0.kind', 'foreground')
        ->assertJsonStructure(['issues' => [['key', 'kind', 'message', 'suggestion' => ['hex', 'ratio']]], 'foregrounds'])
        ->assertJsonCount(7, 'foregrounds');

    $this->actingAs($admin)->postJson('/settings/branding/palette/check', ['colors' => ['primary' => '#F65F1E']])->assertJsonPath('ok', true);

    expect(savedBranding($tenant)->colors)->toBeNull();
});

it('la consulta tolera cualquier entrada sin errores internos', function (mixed $payload): void {
    $this->actingAs(paletteAdmin())->postJson('/settings/branding/palette/check', ['colors' => $payload])->assertOk()->assertJsonStructure(['ok', 'colors', 'issues']);
})->with([
    'vacío' => [[]],
    'texto' => ['hola'],
    'nulo' => [null],
    'valores raros' => [['primary' => ['x'], 'accent' => 12, 'info' => null, '--evil' => 'x']],
]);

it('limita las consultas de la paleta por minuto', function (): void {
    $admin = paletteAdmin();

    for ($i = 0; $i < 120; $i++) {
        $this->actingAs($admin)->postJson('/settings/branding/palette/check', ['colors' => []])->assertOk();
    }

    $this->actingAs($admin)->postJson('/settings/branding/palette/check', ['colors' => []])->assertStatus(429);
});

it('el panel de la empresa recibe su primario como datos compartidos y en la primera carga, sin parpadeo', function (): void {
    $admin = paletteAdmin();
    $this->actingAs($admin)->put('/settings/branding/colors', ['colors' => ['primary' => '#2164DA']]);

    $this->actingAs($admin)->get('/account')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.tenant.theme', ['--color-primary' => '#2164DA', '--color-primary-foreground' => '#F6F1EA']));

    $html = $this->actingAs($admin)->get('/account')->getContent();

    expect($html)->toContain(':root{--color-primary:#2164DA;--color-primary-foreground:#F6F1EA;}')
        ->and($html)->toMatch('/<style id="brand-theme" nonce="[^"]+">:root\{[^<]+\}<\/style>/');
});

it('con la paleta por defecto no se inyecta nada', function (): void {
    $admin = paletteAdmin();

    $this->actingAs($admin)->get('/account')->assertInertia(fn (AssertableInertia $page) => $page->where('auth.tenant.theme', null));

    expect($this->actingAs($admin)->get('/account')->getContent())->not->toContain(':root{--color-');
});

it('la paleta de una empresa no aparece en otra', function (): void {
    $a = paletteAdmin();
    $b = paletteAdmin();
    $this->actingAs($a)->put('/settings/branding/colors', ['colors' => ['primary' => '#2164DA']]);

    $this->actingAs($b)->get('/account')->assertInertia(fn (AssertableInertia $page) => $page->where('auth.tenant.theme', null));
    $this->actingAs($b)->get('/settings/branding')->assertInertia(fn (AssertableInertia $page) => $page->where('palette.colors.primary', '#F65F1E'));

    expect($this->actingAs($b)->get('/account')->getContent())->not->toContain('#2164DA')
        ->and(savedBranding($b->tenant()->firstOrFail())->colors)->toBeNull();
});

it('la pantalla de marca entrega los colores efectivos y los de fábrica', function (): void {
    $admin = paletteAdmin();
    $this->actingAs($admin)->put('/settings/branding/colors', ['colors' => ['primary' => '#2164DA']]);

    $this->actingAs($admin)->get('/settings/branding')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('palette.colors.primary', '#2164DA')
        ->where('palette.colors.secondary', '#2E5EAA')
        ->where('palette.defaults.primary', '#F65F1E')
        ->has('palette.defaults', 10));
});

it('el nombre de la empresa con código nunca llega como CSS ni como HTML a la primera carga', function (): void {
    $admin = paletteAdmin();
    $admin->tenant()->firstOrFail()->forceFill(['name' => '</style><script>alert(1)</script>'])->save();

    $html = $this->actingAs($admin)->get('/account')->getContent();

    expect($html)->not->toContain('<script>alert(1)</script>');
});
