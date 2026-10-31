<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;

/**
 * Migra la base de pruebas una sola vez con el usuario de migraciones (la aplicación no tiene DDL)
 * y envuelve cada prueba en una transacción que se deshace al terminar.
 */
trait UsesMigratedDatabase
{
    use DatabaseTransactions;

    private static bool $schemaReady = false;

    protected function setUpUsesMigratedDatabase(): void
    {
        if (self::$schemaReady) {
            return;
        }

        Artisan::call('migrate:fresh', ['--database' => 'mysql_migrator', '--force' => true]);
        self::$schemaReady = true;
    }
}
