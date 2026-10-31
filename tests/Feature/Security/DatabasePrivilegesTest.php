<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * @return list<string>
 */
function firstColumn(string $connection, string $sql): array
{
    return array_values(array_map(
        static fn (object $row): string => (string) array_values((array) $row)[0],
        DB::connection($connection)->select($sql),
    ));
}

it('el usuario de la aplicación solo tiene permisos de datos', function (): void {
    $grants = implode("\n", firstColumn('mysql', 'SHOW GRANTS FOR CURRENT_USER()'));
    $database = (string) config('database.connections.mysql.database');

    expect($grants)
        ->toContain("GRANT SELECT, INSERT, UPDATE, DELETE ON `{$database}`.*")
        ->not->toContain('ALL PRIVILEGES')
        ->not->toContain('GRANT OPTION')
        ->not->toMatch('/\b(CREATE|DROP|ALTER|INDEX|FILE|SUPER|PROCESS|REFERENCES|TRIGGER)\b/');
});

it('el usuario de la aplicación no puede ejecutar DDL', function (): void {
    expect(fn () => DB::connection('mysql')->statement('CREATE TABLE privilege_probe (id INT)'))
        ->toThrow(QueryException::class, 'denied');

    expect(fn () => DB::connection('mysql')->statement('DROP TABLE IF EXISTS privilege_probe'))
        ->toThrow(QueryException::class, 'denied');
});

it('el usuario de la aplicación no puede leer las credenciales de MySQL', function (): void {
    expect(fn () => DB::connection('mysql')->select('SELECT user FROM mysql.user'))
        ->toThrow(QueryException::class, 'denied');
});

it('el usuario de migraciones puede crear y borrar tablas', function (): void {
    $connection = DB::connection('mysql_migrator');

    try {
        $connection->statement('CREATE TABLE privilege_probe (id INT)');

        expect($connection->select("SHOW TABLES LIKE 'privilege_probe'"))->toHaveCount(1);
    } finally {
        $connection->statement('DROP TABLE IF EXISTS privilege_probe');
    }
});

it('los dos usuarios solo ven las bases de datos del proyecto', function (): void {
    // performance_schema es de solo lectura y MySQL la muestra a cualquier usuario.
    $allowed = ['information_schema', 'performance_schema', 'tikarochat', 'tikarochat_test'];

    foreach (['mysql', 'mysql_migrator'] as $connection) {
        $extra = array_diff(firstColumn($connection, 'SHOW DATABASES'), $allowed);

        expect($extra)->toBe([], "{$connection} ve bases que no son del proyecto");
    }
});
