<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Branding\Palette;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Tenancy\TenantContext;
use App\Widget\WidgetCorner;
use App\Widget\WidgetMode;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\Images;

beforeEach(function (): void {
    Storage::fake('local');
    $this->withoutVite();
});

function brandFrame(Tenant $tenant): TestResponse
{
    return test()->get('/widget/frame?key='.$tenant->widget_key.'&parent='.urlencode('https://tienda-ejemplo.com'))->assertOk();
}

describe('los colores en el widget', function (): void {
    it('con la paleta por defecto no añade estilos de empresa', function (): void {
        $tenant = widgetTenant();

        $html = brandFrame($tenant)->getContent();

        expect($html)->not->toContain('id="brand-theme"');
    });

    it('aplica los colores que la empresa cambió con su texto calculado, y solo esos', function (): void {
        $tenant = widgetTenant();
        brandPalette($tenant, ['primary' => '#2164DA', 'danger' => '#8E1F23']);

        $html = brandFrame($tenant)->getContent();

        preg_match('/<style id="brand-theme" nonce="[^"]+">(.*?)<\/style>/s', $html, $matches);
        $css = html_entity_decode($matches[1] ?? '');
        expect($css)->toStartWith('html:root[data-theme]{')
            ->and($css)->toContain('--color-primary:#2164DA;')
            ->and($css)->toContain('--color-primary-foreground:')
            ->and($css)->toContain('--color-danger:#8E1F23;')
            ->and($css)->not->toContain('--color-secondary')
            ->and($css)->not->toContain('--color-background')
            ->and($css)->toMatch('/\Ahtml:root\[data-theme\]\{(--color-[a-z-]+:#[0-9A-F]{6};)+\}\z/');
    });

    it('el estilo lleva el nonce de la política de contenido de la página', function (): void {
        $tenant = widgetTenant();
        brandPalette($tenant, ['primary' => '#2164DA']);

        $response = brandFrame($tenant);

        preg_match('/<style id="brand-theme" nonce="([^"]+)"/', $response->getContent(), $style);
        preg_match("/style-src 'self' 'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy'), $policy);
        expect($style[1] ?? null)->not->toBeNull()->toBe($policy[1] ?? 'x');
    });

    it('no mezcla los colores de una empresa con los de otra', function (): void {
        $mine = widgetTenant();
        $theirs = widgetTenant();
        brandPalette($theirs, ['primary' => '#2164DA']);

        expect(brandFrame($mine)->getContent())->not->toContain('#2164DA');
    });

    it('el CSS solo puede llevar colores de la lista cerrada con formato #RRGGBB', function (): void {
        $css = Palette::css([
            '--color-primary' => '#2164DA',
            '--color-primary-foreground' => 'red; } body { display:none',
            '--color-evil' => '#000000',
            '--font-body' => '#000000',
            '--color-secondary' => "#2E5EAA\n",
            '--color-accent' => 'url(javascript:alert(1))',
            '--color-text' => '#12345',
            0 => '#FFFFFF',
        ], 'html:root[data-theme]');

        expect($css)->toBe('html:root[data-theme]{--color-primary:#2164DA;}');
    });

    it('el selector también es una lista cerrada', function (): void {
        expect(Palette::css(['--color-primary' => '#2164DA'], 'body, script'))->toBe('')
            ->and(Palette::css(['--color-primary' => '#2164DA']))->toBe(':root{--color-primary:#2164DA;}');
    });

    it('widgetVariables ignora lo que no es una paleta válida', function (): void {
        expect(Palette::widgetVariables(null, null))->toBe([])
            ->and(Palette::widgetVariables('texto', []))->toBe([])
            ->and(Palette::widgetVariables(['primary' => 'no-es-color', 'otra' => '#FFFFFF'], []))->toBe([])
            ->and(Palette::widgetVariables(['primary' => '#2164DA'], null))->toBe(['--color-primary' => '#2164DA'])
            ->and(Palette::widgetVariables(['primary' => '#2164DA', 'background' => '#101820'], ['primary' => '#F6F1EA', 'background' => '#FFFFFF']))
            ->toBe(['--color-primary' => '#2164DA', '--color-primary-foreground' => '#F6F1EA', '--color-background' => '#101820']);
    });

    it('un nombre de empresa hostil no llega al CSS', function (): void {
        $tenant = widgetTenant();
        $tenant->forceFill(['name' => '</style><script>alert(1)</script>{color:red}'])->save();
        brandPalette($tenant, ['primary' => '#2164DA']);

        $html = brandFrame($tenant)->getContent();

        expect($html)->not->toContain('<script>alert(1)</script>');
        preg_match('/<style id="brand-theme"[^>]*>(.*?)<\/style>/s', $html, $matches);
        expect($matches[1] ?? '')->not->toContain('alert');
    });
});

describe('el logo y el nombre', function (): void {
    function uploadBrandLogo(Tenant $tenant): void
    {
        test()->actingAs(widgetAdmin($tenant))->post('/settings/branding/logo', ['logo' => Images::upload('logo.png', Images::png())])->assertSessionHasNoErrors();
        auth()->logout();
    }

    it('sin logo no hay dirección de logo y su ruta da 404', function (): void {
        $tenant = widgetTenant();

        expect(brandFrame($tenant)->viewData('config')['logo'])->toBeNull();
        $this->get('/widget/logo?key='.$tenant->widget_key)->assertNotFound();
    });

    it('con logo, el marco lo pide por la llave y con el identificador del archivo, y se entrega la imagen', function (): void {
        $tenant = widgetTenant();
        uploadBrandLogo($tenant);
        $file = app(TenantContext::class)->run($tenant, fn () => TenantBranding::current()->logo());

        $url = brandFrame($tenant)->viewData('config')['logo'];
        expect($url)->toBe('/widget/logo?key='.$tenant->widget_key.'&v='.$file?->id);

        $response = $this->get($url)->assertOk();
        expect($response->headers->get('Content-Type'))->toBe('image/png')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('Cache-Control'))->toContain('max-age=86400')
            ->and((string) $response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'")
            ->and($response->headers->get('Set-Cookie'))->toBeNull();
    });

    it('el logo de otra empresa no se entrega con mi llave, ni con una llave inexistente o de un widget apagado', function (): void {
        $mine = widgetTenant();
        $theirs = widgetTenant();
        $off = widgetTenant(enabled: false);
        uploadBrandLogo($theirs);
        uploadBrandLogo($off);

        $this->get('/widget/logo?key='.$mine->widget_key)->assertNotFound();
        $this->get('/widget/logo?key='.str_repeat('a', 32))->assertNotFound();
        $this->get('/widget/logo?key='.$off->widget_key)->assertNotFound();
        $this->get('/widget/logo?key=corta')->assertNotFound();
        $this->get('/widget/logo?key[]=x')->assertNotFound();
        $this->get('/widget/logo')->assertNotFound();
    });

    it('el logo lleva el límite de peticiones del marco', function (): void {
        config(['widget.limits.frames_per_ip_per_minute' => 1]);
        $tenant = widgetTenant();

        $this->get('/widget/logo?key='.$tenant->widget_key)->assertNotFound();
        $this->get('/widget/logo?key='.$tenant->widget_key)->assertStatus(429);
    });

    it('el nombre de la empresa del marco es el de la marca', function (): void {
        $tenant = widgetTenant();
        $tenant->forceFill(['name' => 'Tienda Ejemplo SAS'])->save();

        expect(brandFrame($tenant)->viewData('config')['company'])->toBe('Tienda Ejemplo SAS');
    });
});

describe('modo y esquina', function (): void {
    it('por defecto el widget es claro y va abajo a la derecha', function (): void {
        $tenant = widgetTenant();

        $config = brandFrame($tenant)->viewData('config');

        expect($config['mode'])->toBe('claro')->and($config['corner'])->toBe('right');
        expect(brandFrame($tenant)->getContent())->toContain('data-theme="claro"');
    });

    it('el administrador los cambia y el marco los usa', function (): void {
        $tenant = widgetTenant();
        $admin = widgetAdmin($tenant);

        $this->actingAs($admin)->put('/settings/widget/appearance', ['mode' => 'oscuro', 'corner' => 'left'])->assertSessionHasNoErrors();
        auth()->logout();

        $response = brandFrame($tenant);
        expect($response->viewData('config')['mode'])->toBe('oscuro')
            ->and($response->viewData('config')['corner'])->toBe('left')
            ->and($response->getContent())->toContain('data-theme="oscuro"');
        $branding = app(TenantContext::class)->run($tenant, fn () => TenantBranding::query()->sole());
        expect($branding->widget_mode)->toBe(WidgetMode::Dark)->and($branding->widget_corner)->toBe(WidgetCorner::Left);
        expect(AuditLog::query()->where('event', 'widget.appearance_changed')->sole()->context)->toEqual(['mode' => 'oscuro', 'corner' => 'left']);
    });

    it('«seguir al visitante» llega al marco para que decida su navegador', function (): void {
        $tenant = widgetTenant();

        $this->actingAs(widgetAdmin($tenant))->put('/settings/widget/appearance', ['mode' => 'sitio', 'corner' => 'right'])->assertSessionHasNoErrors();
        auth()->logout();

        expect(brandFrame($tenant)->viewData('config')['mode'])->toBe('sitio');
    });

    it('la pantalla muestra lo guardado', function (): void {
        $tenant = widgetTenant();
        $admin = widgetAdmin($tenant);
        $this->actingAs($admin)->put('/settings/widget/appearance', ['mode' => 'oscuro', 'corner' => 'left']);

        $props = $this->actingAs($admin)->get('/settings/widget')->viewData('page')['props'];

        expect($props['appearance'])->toBe(['mode' => 'oscuro', 'corner' => 'left']);
    });

    it('guardar lo mismo no audita nada', function (): void {
        $tenant = widgetTenant();
        $admin = widgetAdmin($tenant);

        $this->actingAs($admin)->put('/settings/widget/appearance', ['mode' => 'oscuro', 'corner' => 'left']);
        $this->actingAs($admin)->put('/settings/widget/appearance', ['mode' => 'oscuro', 'corner' => 'left']);

        expect(AuditLog::query()->where('event', 'widget.appearance_changed')->count())->toBe(1);
    });

    it('solo acepta las opciones de la lista cerrada', function (array $payload): void {
        $tenant = widgetTenant();

        $this->actingAs(widgetAdmin($tenant))->put('/settings/widget/appearance', $payload)->assertSessionHasErrors();

        expect(app(TenantContext::class)->run($tenant, fn () => TenantBranding::query()->count()))->toBe(0);
    })->with([
        'modo inventado' => [['mode' => 'rojo', 'corner' => 'left']],
        'esquina inventada' => [['mode' => 'claro', 'corner' => 'top']],
        'sin modo' => [['corner' => 'left']],
        'sin esquina' => [['mode' => 'claro']],
        'arreglos' => [['mode' => ['claro'], 'corner' => ['left']]],
        'css' => [['mode' => 'claro; }', 'corner' => 'left']],
    ]);

    it('no lo cambian el agente ni el observador', function (): void {
        $tenant = widgetTenant();

        foreach ([Role::Agent, Role::Observer] as $role) {
            $this->actingAs(ticketStaff($tenant, $role))->put('/settings/widget/appearance', ['mode' => 'oscuro', 'corner' => 'left'])->assertForbidden();
        }
    });

    it('cambiar la apariencia de una empresa no toca la de otra', function (): void {
        $mine = widgetTenant();
        $theirs = widgetTenant();

        $this->actingAs(widgetAdmin($mine))->put('/settings/widget/appearance', ['mode' => 'oscuro', 'corner' => 'left']);
        auth()->logout();

        expect(brandFrame($theirs)->viewData('config')['mode'])->toBe('claro');
    });
});
