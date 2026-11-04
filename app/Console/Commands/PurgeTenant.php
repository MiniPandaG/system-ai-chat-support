<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Knowledge\Vector\IndexUnavailable;
use App\Models\Tenant;
use App\Privacy\TenantPurge;
use Illuminate\Console\Command;

use function Laravel\Prompts\text;

/**
 * Borra una empresa y todo lo suyo (RS-17 y RS-18). Es irreversible y por eso pide escribir el número de la empresa;
 * con --dry-run solo cuenta lo que se borraría. Se ejecuta en el servidor, por quien lo administra: ninguna ruta web
 * puede hacerlo. Antes hay que parar los trabajadores de la cola o vaciar sus trabajos pendientes de esa empresa.
 */
class PurgeTenant extends Command
{
    protected $signature = 'tenant:purge {tenant : Número de la empresa (ver plans:list)} {--dry-run : Solo cuenta lo que se borraría} {--confirm= : El número de la empresa, para confirmar sin preguntar}';

    protected $description = 'Borra una empresa con todos sus datos, archivos e índice. No se puede deshacer. Queda en la auditoría.';

    public function handle(TenantPurge $purge): int
    {
        $tenant = Tenant::query()->find((int) $this->argument('tenant'));

        if ($tenant === null) {
            $this->error('No existe una empresa con ese número. Usa plans:list para verlas.');

            return self::FAILURE;
        }

        $counts = $purge->counts($tenant);

        $this->line("Empresa {$tenant->id}: «{$tenant->name}»");
        $this->table(['Tabla', 'Filas'], collect($counts)->map(fn (int $rows, string $table): array => [$table, $rows])->values()->all());

        if ($this->option('dry-run')) {
            $this->info('Prueba en seco: no se borró nada.');

            return self::SUCCESS;
        }

        if ($this->confirmation($tenant) !== (string) $tenant->id) {
            $this->error("No se borró nada: para confirmar hay que dar el número de la empresa ({$tenant->id}).");

            return self::FAILURE;
        }

        try {
            $deleted = $purge->purge($tenant);
        } catch (IndexUnavailable) {
            $this->error('No se pudo vaciar el índice de conocimiento (¿está encendido qdrant?). No se borró nada de la base; repite el comando cuando esté disponible.');

            return self::FAILURE;
        }

        $this->info('Listo: la empresa se borró ('.array_sum($deleted).' filas, sus archivos y su índice).');

        return self::SUCCESS;
    }

    private function confirmation(Tenant $tenant): string
    {
        $given = $this->option('confirm');

        if (is_string($given) && $given !== '') {
            return $given;
        }

        if (! $this->input->isInteractive()) {
            return '';
        }

        return text("Esto es irreversible. Escribe {$tenant->id} para borrar «{$tenant->name}»");
    }
}
