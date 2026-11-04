<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Pdo\Mysql;

/** Arranca la aplicación como si fuera producción, con estas opciones de conexión a MySQL. */
function bootAsProduction(array $mysqlOptions): void
{
    app()->detectEnvironment(fn (): string => 'production');
    config(['database.connections.mysql.options' => $mysqlOptions]);
    (new AppServiceProvider(app()))->boot();
}

it('en producción la aplicación se niega a arrancar sin conexión cifrada a la base de datos', function (): void {
    expect(fn () => bootAsProduction([]))->toThrow(LogicException::class, 'MYSQL_ATTR_SSL_CA');
});

it('en producción arranca cuando la conexión lleva su autoridad certificadora', function (): void {
    bootAsProduction([Mysql::ATTR_SSL_CA => '/etc/ssl/gecko/ca.pem', Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true]);

    expect(config('database.connections.mysql.options'))->toHaveKey(Mysql::ATTR_SSL_CA);
});

it('fuera de producción (local y pruebas) no se exige', function (): void {
    expect(app()->isProduction())->toBeFalse();
    (new AppServiceProvider(app()))->boot();
    expect(true)->toBeTrue();
});

it('con la autoridad certificadora, las dos conexiones verifican el certificado del servidor', function (): void {
    $_ENV['MYSQL_ATTR_SSL_CA'] = $_SERVER['MYSQL_ATTR_SSL_CA'] = '/etc/ssl/gecko/ca.pem';
    $config = require base_path('config/database.php');
    unset($_ENV['MYSQL_ATTR_SSL_CA'], $_SERVER['MYSQL_ATTR_SSL_CA']);

    foreach (['mysql', 'mysql_migrator'] as $connection) {
        expect($config['connections'][$connection]['options'])->toMatchArray([
            Mysql::ATTR_SSL_CA => '/etc/ssl/gecko/ca.pem',
            Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true,
        ]);
    }
});

it('sin autoridad certificadora no se fuerza nada (local)', function (): void {
    unset($_ENV['MYSQL_ATTR_SSL_CA'], $_SERVER['MYSQL_ATTR_SSL_CA']);
    $config = require base_path('config/database.php');

    expect($config['connections']['mysql']['options'])->toBe([]);
});
