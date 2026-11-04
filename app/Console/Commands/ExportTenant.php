<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Privacy\TenantExport;
use Illuminate\Console\Command;

/**
 * Exporta los datos de una empresa a un ZIP cifrado (RS-17). La contraseña se muestra una sola vez, aquí, y no se guarda.
 * Se ejecuta en el servidor, por quien lo administra: ninguna ruta web puede hacerlo.
 */
class ExportTenant extends Command
{
    protected $signature = 'tenant:export {tenant : Número de la empresa (ver plans:list)}';

    protected $description = 'Exporta los datos de una empresa a un ZIP cifrado. Queda en la auditoría.';

    public function handle(TenantExport $export): int
    {
        $tenant = Tenant::query()->find((int) $this->argument('tenant'));

        if ($tenant === null) {
            $this->error('No existe una empresa con ese número. Usa plans:list para verlas.');

            return self::FAILURE;
        }

        $result = $export->export($tenant);

        $this->info('Listo: '.array_sum($result['rows']).' filas y '.$result['files'].' archivos.');
        $this->line("Archivo: {$result['path']}");
        $this->line("Contraseña del ZIP (se muestra una sola vez; guárdala aparte): {$result['password']}");
        $this->warn('El ZIP tiene datos personales. Entrégalo por un canal seguro y bórralo del servidor cuando ya no haga falta.');

        return self::SUCCESS;
    }
}
