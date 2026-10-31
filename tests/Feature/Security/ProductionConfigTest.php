<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * @return array<string, string|null>
 */
function envExample(): array
{
    return Dotenv::parse((string) file_get_contents(base_path('.env.example')));
}

it('el .env.example viene seguro por defecto para producción', function (): void {
    $env = envExample();

    expect($env['APP_ENV'])->toBe('production')
        ->and($env['APP_DEBUG'])->toBe('false')
        ->and($env['SESSION_SECURE_COOKIE'])->toBe('true')
        ->and($env['SESSION_HTTP_ONLY'])->toBe('true')
        ->and($env['SESSION_ENCRYPT'])->toBe('true')
        ->and($env['APP_KEY'])->toBe('');
});

it('el .env.example no contiene secretos reales', function (): void {
    foreach (envExample() as $name => $value) {
        if (preg_match('/PASSWORD|SECRET|KEY|TOKEN/', $name) !== 1) {
            continue;
        }

        expect(in_array($value, ['', 'CHANGE_ME', 'null'], true))
            ->toBeTrue("{$name} tiene un valor que parece real");
    }
});

it('las cookies de sesión llevan HttpOnly, Secure y SameSite', function (): void {
    $name = Str::slug((string) config('app.name')).'-session';
    $cookie = collect($this->get('/')->headers->getCookies())
        ->first(fn (Cookie $cookie): bool => $cookie->getName() === $name);

    expect($cookie)->toBeInstanceOf(Cookie::class)
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax');
});

it('la configuración de sesión es estricta', function (): void {
    expect(config('session.http_only'))->toBeTrue()
        ->and(config('session.secure'))->toBeTruthy()
        ->and(config('session.encrypt'))->toBeTrue()
        ->and(config('session.expire_on_close'))->toBeTrue()
        ->and(config('session.same_site'))->toBeIn(['lax', 'strict'])
        ->and(config('session.lifetime'))->toBeLessThanOrEqual(60);
});

it('la depuración está apagada y la aplicación usa español', function (): void {
    expect(config('app.debug'))->toBeFalse()
        ->and(config('app.locale'))->toBe('es');
});

it('los modelos son estrictos fuera de producción', function (): void {
    expect(Model::preventsLazyLoading())->toBeTrue()
        ->and(Model::preventsSilentlyDiscardingAttributes())->toBeTrue()
        ->and(Model::preventsAccessingMissingAttributes())->toBeTrue();
});

it('la base de datos no tiene credenciales por defecto', function (): void {
    expect(config('database.connections.mysql.username'))->not->toBe('root')
        ->and(config('database.connections.mysql.username'))->not->toBe(config('database.connections.mysql_migrator.username'))
        ->and(config('database.connections.mysql.password'))->not->toBe('')
        ->and(config('database.default'))->toBe('mysql');
});
