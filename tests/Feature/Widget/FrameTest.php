<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Widget\Origins;

beforeEach(fn () => $this->withoutVite());

function frameUrl(string $key, ?string $parent): string
{
    return '/widget/frame?key='.$key.($parent === null ? '' : '&parent='.urlencode($parent));
}

describe('quién recibe el marco', function (): void {
    it('un sitio permitido recibe la página con la configuración del widget', function (): void {
        $tenant = widgetTenant();
        $tenant->forceFill(['name' => 'Tienda "Ejemplo" <b>'])->save();

        $response = $this->get(frameUrl($tenant->widget_key, 'https://tienda-ejemplo.com'))->assertOk();

        $config = $response->viewData('config');
        expect($config['key'])->toBe($tenant->widget_key)
            ->and($config['parent'])->toBe('https://tienda-ejemplo.com')
            ->and($config['company'])->toBe('Tienda "Ejemplo" <b>')
            ->and($config['copy']['welcome_start'])->toBe('Iniciar conversación')
            ->and($config['mode'])->toBe('claro')
            ->and($config['corner'])->toBe('right');
    });

    it('el nombre de la empresa se escapa en la página y no abre etiquetas ni atributos', function (): void {
        $tenant = widgetTenant();
        $tenant->forceFill(['name' => '"><script>alert(1)</script>'])->save();

        $html = $this->get(frameUrl($tenant->widget_key, 'https://tienda-ejemplo.com'))->assertOk()->getContent();

        expect($html)->not->toContain('<script>alert(1)</script>')
            ->and($html)->not->toContain('"><script>');
    });

    it('un sitio que la empresa no permite recibe 403, sin nombrar a la empresa', function (string $parent): void {
        $tenant = widgetTenant();

        $response = $this->get(frameUrl($tenant->widget_key, $parent))->assertForbidden();

        expect($response->getContent())->not->toContain($tenant->name);
    })->with([
        'otro dominio' => ['https://otro-sitio.com'],
        'subdominio' => ['https://www.tienda-ejemplo.com'],
        'otro puerto' => ['https://tienda-ejemplo.com:8443'],
        'otro esquema' => ['http://tienda-ejemplo.com'],
        'con ruta' => ['https://tienda-ejemplo.com/tienda'],
        'con credenciales' => ['https://usuario@tienda-ejemplo.com'],
        'vacío' => [''],
        'basura' => ['no es un origen'],
    ]);

    it('sin el sitio que lo incrusta recibe 403', function (): void {
        $tenant = widgetTenant();

        $this->get(frameUrl($tenant->widget_key, null))->assertForbidden();
    });

    it('un sitio permitido por otra empresa no sirve', function (): void {
        $mine = widgetTenant();
        $theirs = widgetTenant();
        widgetOrigin($theirs, 'https://sitio-de-otra-empresa.com');

        $this->get(frameUrl($mine->widget_key, 'https://sitio-de-otra-empresa.com'))->assertForbidden();
    });

    it('una llave inexistente, mal formada o de un widget apagado recibe 404', function (): void {
        $off = widgetTenant(enabled: false);

        $this->get(frameUrl(str_repeat('a', 32), 'https://tienda-ejemplo.com'))->assertNotFound();
        $this->get(frameUrl('corta', 'https://tienda-ejemplo.com'))->assertNotFound();
        $this->get('/widget/frame?key[]=x&parent=https://tienda-ejemplo.com')->assertNotFound();
        $this->get(frameUrl($off->widget_key, 'https://tienda-ejemplo.com'))->assertNotFound();
    });
});

describe('cabeceras del marco', function (): void {
    it('solo los sitios de la empresa pueden incrustarlo (frame-ancestors) y no lleva X-Frame-Options', function (): void {
        $tenant = widgetTenant();
        widgetOrigin($tenant, 'https://www.tienda-ejemplo.com');
        widgetOrigin($theirs = widgetTenant(), 'https://de-otra-empresa.com');

        $response = $this->get(frameUrl($tenant->widget_key, 'https://tienda-ejemplo.com'))->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');
        expect($csp)->toContain('frame-ancestors https://tienda-ejemplo.com https://www.tienda-ejemplo.com')
            ->and($csp)->not->toContain('de-otra-empresa')
            ->and($csp)->not->toContain("frame-ancestors 'none'")
            ->and($response->headers->has('X-Frame-Options'))->toBeFalse();
    });

    it('mantiene la política estricta de siempre: sin unsafe-inline ni unsafe-eval, objetos y base cerrados', function (): void {
        $tenant = widgetTenant();

        $csp = (string) $this->get(frameUrl($tenant->widget_key, 'https://tienda-ejemplo.com'))->headers->get('Content-Security-Policy');

        expect($csp)->toContain("default-src 'self'")
            ->and($csp)->toContain("object-src 'none'")
            ->and($csp)->toContain("base-uri 'self'")
            ->and($csp)->not->toContain('unsafe-inline')
            ->and($csp)->not->toContain('unsafe-eval')
            ->and($csp)->not->toContain('*');
    });

    it('no se guarda en caché ni se indexa y puede cargarse desde otro sitio', function (): void {
        $tenant = widgetTenant();

        $response = $this->get(frameUrl($tenant->widget_key, 'https://tienda-ejemplo.com'))->assertOk();

        expect($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('X-Robots-Tag'))->toBe('noindex')
            ->and($response->headers->get('Cross-Origin-Resource-Policy'))->toBe('cross-origin')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    });

    it('el resto del sitio sigue sin poder incrustarse en ningún lado', function (string $path): void {
        $response = $this->get($path);

        expect($response->headers->get('X-Frame-Options'))->toBe('DENY')
            ->and((string) $response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'")
            ->and($response->headers->get('Cross-Origin-Resource-Policy'))->toBe('same-origin');
    })->with(['/login', '/forgot-password', '/widget/api/conversation']);

    it('la API del widget tampoco puede enmarcarse', function (): void {
        $tenant = widgetTenant();

        $response = widgetSend($this, $tenant)->assertCreated();

        expect($response->headers->get('X-Frame-Options'))->toBe('DENY');
    });

    it('la petición de un sitio con la llave en el marco no deja pasar a un sitio no permitido por el atributo del navegador', function (): void {
        $tenant = widgetTenant();
        $this->get(frameUrl($tenant->widget_key, 'https://tienda-ejemplo.com'))->assertOk();

        // El atributo que abre el marco es solo de esa petición: la siguiente, sin él, vuelve a la política cerrada.
        $next = $this->get('/login');

        expect($next->headers->get('X-Frame-Options'))->toBe('DENY');
    });
});

describe('los orígenes', function (): void {
    it('se normalizan a esquema, servidor y puerto exactos', function (?string $expected, string $input): void {
        expect(Origins::normalize($input))->toBe($expected);
    })->with([
        'https simple' => ['https://tienda.com', 'https://tienda.com'],
        'mayúsculas' => ['https://tienda.com', 'HTTPS://Tienda.COM'],
        'barra final' => ['https://tienda.com', 'https://tienda.com/'],
        'puerto por defecto' => ['https://tienda.com', 'https://tienda.com:443'],
        'puerto propio' => ['https://tienda.com:8443', 'https://tienda.com:8443'],
        'subdominio' => ['https://www.tienda.co.uk', 'https://www.tienda.co.uk'],
        'espacios' => ['https://tienda.com', "  https://tienda.com \t"],
        'localhost http' => ['http://localhost:3000', 'http://localhost:3000'],
        '127.0.0.1 http' => ['http://127.0.0.1:9090', 'http://127.0.0.1:9090'],
        'http no local' => [null, 'http://tienda.com'],
        'ruta' => [null, 'https://tienda.com/chat'],
        'consulta' => [null, 'https://tienda.com?x=1'],
        'fragmento' => [null, 'https://tienda.com#x'],
        'credenciales' => [null, 'https://u:p@tienda.com'],
        'comodín' => [null, 'https://*.tienda.com'],
        'solo dominio' => [null, 'tienda.com'],
        'otro esquema' => [null, 'ftp://tienda.com'],
        'javascript' => [null, 'javascript:alert(1)'],
        'ip pública' => [null, 'https://203.0.113.9'],
        'sin punto' => [null, 'https://intranet'],
        'puerto absurdo' => [null, 'https://tienda.com:99999'],
        'salto de línea' => [null, "https://tienda.com\nhttps://otra.com"],
        'espacio dentro' => [null, 'https://tienda .com'],
        'barra invertida' => [null, 'https://tienda.com\\@otra.com'],
        'vacío' => [null, ''],
        'muy largo' => [null, 'https://'.str_repeat('a', 300).'.com'],
    ]);
});
