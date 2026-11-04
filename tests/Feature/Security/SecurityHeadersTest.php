<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('envía las cabeceras de seguridad en la página principal', function (): void {
    $response = $this->get('/login')->assertOk();

    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
    $response->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');
    $response->assertHeader('Permissions-Policy');
    expect((string) $response->headers->get('Permissions-Policy'))
        ->toContain('camera=()')
        ->toContain('microphone=()')
        ->toContain('geolocation=()');
});

it('envía las mismas cabeceras en una respuesta de error', function (): void {
    $this->get('/ruta-que-no-existe')
        ->assertNotFound()
        ->assertHeader('Content-Security-Policy')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY');
});

it('usa una CSP estricta sin unsafe-inline ni unsafe-eval', function (): void {
    $csp = (string) $this->get('/login')->headers->get('Content-Security-Policy');

    expect($csp)
        ->toContain("default-src 'self'")
        ->toContain("object-src 'none'")
        ->toContain("base-uri 'self'")
        ->toContain("frame-ancestors 'none'")
        ->not->toContain('unsafe-inline')
        ->not->toContain('unsafe-eval')
        ->not->toContain('*');
});

it('usa un nonce distinto en cada respuesta y coincide con el de las etiquetas', function (): void {
    $first = $this->get('/login');
    $second = $this->get('/login');

    preg_match("/'nonce-([^']+)'/", (string) $first->headers->get('Content-Security-Policy'), $inHeader);
    preg_match("/'nonce-([^']+)'/", (string) $second->headers->get('Content-Security-Policy'), $inSecondHeader);

    expect($inHeader[1] ?? null)->not->toBeNull()
        ->and($inHeader[1])->not->toBe($inSecondHeader[1] ?? null);

    $first->assertSee('nonce="'.$inHeader[1].'"', false);
});

it('envía HSTS solo sobre HTTPS', function (): void {
    $this->get('http://localhost/up')->assertHeaderMissing('Strict-Transport-Security');

    $this->get('https://localhost/up')->assertHeader(
        'Strict-Transport-Security',
        'max-age='.config('security.hsts.max_age').'; includeSubDomains',
    );
});

it('en producción añade upgrade-insecure-requests y nunca abre orígenes de desarrollo', function (): void {
    $publicPath = sys_get_temp_dir().'/geckochat-hot-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($publicPath);
    File::put($publicPath.'/hot', 'http://127.0.0.1:5173');
    $this->app->usePublicPath($publicPath);

    try {
        $this->app['env'] = 'production';
        $csp = (string) $this->get('/up')->headers->get('Content-Security-Policy');

        expect($csp)
            ->toContain('upgrade-insecure-requests')
            ->not->toContain('5173')
            ->not->toContain('ws:');
    } finally {
        File::deleteDirectory($publicPath);
    }
});

it('en local solo abre el servidor de Vite mientras está encendido', function (): void {
    $publicPath = sys_get_temp_dir().'/geckochat-hot-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($publicPath);
    $this->app->usePublicPath($publicPath);
    $this->app['env'] = 'local';

    try {
        expect((string) $this->get('/up')->headers->get('Content-Security-Policy'))->not->toContain('5173');

        File::put($publicPath.'/hot', 'http://127.0.0.1:5173');

        expect((string) $this->get('/up')->headers->get('Content-Security-Policy'))
            ->toContain('http://127.0.0.1:5173')
            ->not->toContain('unsafe-inline');
    } finally {
        File::deleteDirectory($publicPath);
    }
});
