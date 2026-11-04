<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Testing\TestResponse;

/** Aplica la configuración de proxies como lo hace el arranque de la aplicación. */
function trustProxiesFrom(string $list): void
{
    config(['app.trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', $list))))]);
    (new AppServiceProvider(app()))->boot();
}

/** Una petición que llega desde `$from` diciendo, por cabeceras reenviadas, que el visitante es 203.0.113.9 y que usó https. */
function forwardedLoginFrom(object $test, string $from): TestResponse
{
    return $test->withServerVariables(['REMOTE_ADDR' => $from, 'HTTPS' => 'off'])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
        ->post('/login', ['email' => 'nadie@ejemplo.com', 'password' => Str::random(24)]);
}

afterEach(function (): void {
    TrustProxies::at([]);
});

it('por defecto no se cree ninguna cabecera reenviada: la IP es la de quien conecta', function (): void {
    expect(config('app.trusted_proxies'))->toBe([]);
    trustProxiesFrom('');

    forwardedLoginFrom($this, '10.0.0.5');

    expect(AuditLog::query()->where('event', 'login.failed')->latest('id')->value('ip_address'))->toBe('10.0.0.5');
});

it('con el proxy declarado, la IP del visitante y el https reenviados se respetan', function (): void {
    trustProxiesFrom('10.0.0.0/8');

    $response = forwardedLoginFrom($this, '10.0.0.5');

    expect(AuditLog::query()->where('event', 'login.failed')->latest('id')->value('ip_address'))->toBe('203.0.113.9')
        ->and($response->headers->get('Strict-Transport-Security'))->not->toBeNull();
});

it('una cabecera reenviada desde una dirección que no es el proxy se ignora', function (): void {
    trustProxiesFrom('10.0.0.0/8');

    $response = forwardedLoginFrom($this, '192.0.2.77');

    expect(AuditLog::query()->where('event', 'login.failed')->latest('id')->value('ip_address'))->toBe('192.0.2.77')
        ->and($response->headers->get('Strict-Transport-Security'))->toBeNull();
});

it('el .env.example trae la variable vacía y explicada', function (): void {
    expect(Dotenv\Dotenv::parse((string) file_get_contents(base_path('.env.example'))))->toHaveKey('TRUSTED_PROXIES')
        ->and(Dotenv\Dotenv::parse((string) file_get_contents(base_path('.env.example')))['TRUSTED_PROXIES'])->toBe('');
});
