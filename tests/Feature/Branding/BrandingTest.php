<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Enums\Role;
use App\Files\FileStore;
use App\Models\AuditLog;
use App\Models\StoredFile;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Images;

beforeEach(function (): void {
    Storage::fake('local');
});

function brandingAdmin(?Tenant $tenant = null): User
{
    $admin = User::factory()->forTenant($tenant ?? Tenant::factory()->create(), Role::Admin)->create();
    $admin->forceFill([
        'two_factor_secret' => encrypt('SECRETOPRUEBA'),
        'two_factor_recovery_codes' => encrypt(json_encode(['x'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $admin;
}

function brandingOf(Tenant $tenant): TenantBranding
{
    return app(TenantContext::class)->run($tenant, fn (): TenantBranding => TenantBranding::current());
}

function logoOf(Tenant $tenant): ?StoredFile
{
    return app(TenantContext::class)->run($tenant, fn (): ?StoredFile => TenantBranding::current()->logo());
}

function uploadLogo(User $admin, string $name, string $content)
{
    return test()->actingAs($admin)->post('/settings/branding/logo', ['logo' => Images::upload($name, $content)]);
}

it('un administrador ve la pantalla de marca con su nombre y sin logo al empezar', function (): void {
    $admin = brandingAdmin();

    $this->actingAs($admin)->get('/settings/branding')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('settings/Branding')
        ->where('name', $admin->tenant()->firstOrFail()->name)
        ->where('logo', null)
        ->where('limits.max_bytes', 2 * 1024 * 1024)
        ->where('copy.title', 'Marca'));
});

it('solo un administrador activo de empresa entra a la marca', function (Role $role): void {
    $user = User::factory()->forTenant(Tenant::factory()->create(), $role)->create();

    $this->actingAs($user)->get('/settings/branding')->assertForbidden();
    $this->actingAs($user)->put('/settings/branding/name', ['name' => 'Otra'])->assertForbidden();
    $this->actingAs($user)->post('/settings/branding/logo', ['logo' => Images::upload('logo.png', Images::png())])->assertForbidden();
    $this->actingAs($user)->delete('/settings/branding/logo')->assertForbidden();
})->with([Role::Agent, Role::Observer]);

it('un visitante y el personal de la plataforma no tocan la marca', function (): void {
    $this->get('/settings/branding')->assertRedirect('/login');
    $this->put('/settings/branding/name', ['name' => 'X'])->assertRedirect('/login');

    $staff = User::factory()->create(['is_platform_admin' => true]);
    $staff->forceFill(['two_factor_secret' => encrypt('S'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()])->save();

    $this->actingAs($staff)->get('/settings/branding')->assertForbidden();
});

it('un administrador sin 2FA no llega a la marca', function (): void {
    $admin = User::factory()->forTenant(Tenant::factory()->create(), Role::Admin)->create();

    $this->actingAs($admin)->get('/settings/branding')->assertRedirect(route('security'));
});

it('sube un logo válido: queda guardado, ligado a la empresa y auditado', function (): void {
    $admin = brandingAdmin();
    $tenant = $admin->tenant()->firstOrFail();

    uploadLogo($admin, 'logo.png', Images::png())->assertSessionHasNoErrors()->assertSessionHas('status', 'Guardamos tu logo.');

    $logo = logoOf($tenant);

    expect($logo)->not->toBeNull()
        ->and($logo?->tenant_id)->toBe($tenant->id)
        ->and(Storage::disk('local')->exists((string) $logo?->path))->toBeTrue()
        ->and(AuditLog::query()->where('event', AuditEvent::BrandingLogoChanged->value)->where('user_id', $admin->id)->exists())->toBeTrue();

    $this->actingAs($admin)->get('/settings/branding')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('logo.width', 128)
        ->where('logo.url', fn (string $url): bool => str_contains($url, '/files/'.$logo?->id) && str_contains($url, 'signature=')));
});

it('reemplazar el logo borra el anterior del disco y de la base', function (): void {
    $admin = brandingAdmin();
    $tenant = $admin->tenant()->firstOrFail();

    uploadLogo($admin, 'uno.png', Images::png());
    $first = logoOf($tenant);
    uploadLogo($admin, 'dos.jpg', Images::jpeg());
    $second = logoOf($tenant);

    expect($second?->id)->not->toBe($first?->id)
        ->and(Storage::disk('local')->exists((string) $first?->path))->toBeFalse()
        ->and(Storage::disk('local')->exists((string) $second?->path))->toBeTrue()
        ->and(app(TenantContext::class)->run($tenant, fn (): int => StoredFile::query()->count()))->toBe(1);
});

it('rechaza archivos inválidos con el motivo exacto y no cambia el logo', function (string $name, string $content, string $message): void {
    $admin = brandingAdmin();
    $tenant = $admin->tenant()->firstOrFail();
    uploadLogo($admin, 'bueno.png', Images::png());
    $before = logoOf($tenant);

    uploadLogo($admin, $name, $content)->assertSessionHasErrors(['logo' => $message]);

    expect(logoOf($tenant)?->id)->toBe($before?->id)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
})->with([
    'SVG' => ['logo.svg', Images::svg(), 'Solo se admiten imágenes PNG, JPEG o WebP. Los SVG y otros formatos no se admiten.'],
    'código PHP con extensión de imagen' => ['logo.png', '<?php echo 1;', 'Solo se admiten imágenes PNG, JPEG o WebP. Los SVG y otros formatos no se admiten.'],
    'extensión que no corresponde' => ['logo.jpg', Images::png(), 'La extensión del archivo no corresponde a lo que contiene. Guárdalo de nuevo con su formato real.'],
    'demasiado pesado' => ['logo.png', Images::png().str_repeat('a', 2 * 1024 * 1024), 'Este archivo pesa 2,0 MB; el máximo es 2,0 MB.'],
]);

it('exige que llegue un archivo', function (array $payload): void {
    $admin = brandingAdmin();

    $this->actingAs($admin)->post('/settings/branding/logo', $payload)->assertSessionHasErrors('logo');
})->with([
    'sin campo' => [[]],
    'texto en vez de archivo' => [['logo' => 'no-es-un-archivo']],
    'lista' => [['logo' => ['uno', 'dos']]],
]);

it('quitar el logo borra el archivo y deja la marca sin imagen', function (): void {
    $admin = brandingAdmin();
    $tenant = $admin->tenant()->firstOrFail();
    uploadLogo($admin, 'logo.png', Images::png());
    $logo = logoOf($tenant);

    $this->actingAs($admin)->delete('/settings/branding/logo')->assertSessionHas('status', 'Quitamos el logo.');

    expect(logoOf($tenant))->toBeNull()
        ->and(Storage::disk('local')->exists((string) $logo?->path))->toBeFalse()
        ->and(AuditLog::query()->where('event', AuditEvent::BrandingLogoRemoved->value)->exists())->toBeTrue();
});

it('el logo de una empresa no aparece ni se descarga desde otra', function (): void {
    $admin = brandingAdmin();
    $tenant = $admin->tenant()->firstOrFail();
    uploadLogo($admin, 'logo.png', Images::png());
    $logo = logoOf($tenant);
    $intruder = brandingAdmin();

    $this->actingAs($intruder)->get('/settings/branding')->assertInertia(fn (AssertableInertia $page) => $page->where('logo', null));
    $this->assertOtherTenantGets404($intruder, 'GET', app(FileStore::class)->temporaryUrl($logo ?? new StoredFile));
    expect(brandingOf($intruder->tenant()->firstOrFail())->logo_file_id)->toBeNull();
});

it('cambia el nombre de la empresa y lo audita', function (): void {
    $admin = brandingAdmin();
    $tenant = $admin->tenant()->firstOrFail();

    $this->actingAs($admin)->put('/settings/branding/name', ['name' => '  Soporte Nuevo SAS  '])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Guardamos el nombre de tu empresa.');

    expect($tenant->fresh()?->name)->toBe('Soporte Nuevo SAS')
        ->and(AuditLog::query()->where('event', AuditEvent::BrandingNameChanged->value)->where('user_id', $admin->id)->exists())->toBeTrue();
});

it('valida el nombre: obligatorio, texto y de hasta 120 caracteres', function (array $payload, string $message): void {
    $admin = brandingAdmin();
    $original = $admin->tenant()->firstOrFail()->name;

    $this->actingAs($admin)->put('/settings/branding/name', $payload)->assertSessionHasErrors(['name' => $message]);

    expect($admin->tenant()->firstOrFail()->fresh()?->name)->toBe($original);
})->with([
    'vacío' => [['name' => ''], 'Escribe el nombre de tu empresa.'],
    'solo espacios' => [['name' => '   '], 'Escribe el nombre de tu empresa.'],
    'demasiado largo' => [['name' => str_repeat('e', 121)], 'El campo nombre no puede superar los 120 caracteres.'],
    'no es texto' => [['name' => ['x']], 'El campo nombre no es válido.'],
]);

it('el nombre con código se guarda como texto y solo cambia la empresa propia', function (): void {
    $admin = brandingAdmin();
    $other = Tenant::factory()->create(['name' => 'Ajena SA']);
    $payload = '<script>alert(1)</script>';

    $this->actingAs($admin)->put('/settings/branding/name', ['name' => $payload, 'id' => $other->id, 'tenant_id' => $other->id])->assertSessionHasNoErrors();

    expect($admin->tenant()->firstOrFail()->fresh()?->name)->toBe($payload)
        ->and($other->fresh()?->name)->toBe('Ajena SA');

    $this->actingAs($admin)->get('/account')->assertInertia(fn (AssertableInertia $page) => $page->where('auth.tenant.name', $payload));
});

it('los datos compartidos llevan el enlace del logo y si el usuario puede editar la marca', function (): void {
    $admin = brandingAdmin();
    uploadLogo($admin, 'logo.png', Images::png());
    $agent = User::factory()->forTenant($admin->tenant()->firstOrFail(), Role::Agent)->create();

    $this->actingAs($admin)->get('/account')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('auth.tenant.logo_url', fn (?string $url): bool => $url !== null && str_contains($url, 'signature='))
        ->where('auth.can.branding', true));

    $this->actingAs($agent)->get('/account')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('auth.tenant.logo_url', fn (?string $url): bool => $url !== null)
        ->where('auth.can.branding', false));
});

it('el enlace del logo es el mismo dentro de una ventana de tiempo y caduca después', function (): void {
    $admin = brandingAdmin();
    uploadLogo($admin, 'logo.png', Images::png());
    $logo = logoOf($admin->tenant()->firstOrFail());
    $store = app(FileStore::class);

    $first = $store->temporaryUrl($logo ?? new StoredFile);
    $second = $store->temporaryUrl($logo ?? new StoredFile);

    expect($first)->toBe($second);

    $this->actingAs($admin)->get($first)->assertOk();
    $this->travel(11)->minutes();
    $this->actingAs($admin)->get($first)->assertForbidden();
});

it('el nombre y el logo de una empresa no alteran los de otra', function (): void {
    $a = brandingAdmin();
    $b = brandingAdmin();

    $this->actingAs($a)->put('/settings/branding/name', ['name' => 'Empresa A']);
    uploadLogo($a, 'logo.png', Images::png());

    expect($b->tenant()->firstOrFail()->fresh()?->name)->not->toBe('Empresa A')
        ->and(logoOf($b->tenant()->firstOrFail()))->toBeNull();
});
