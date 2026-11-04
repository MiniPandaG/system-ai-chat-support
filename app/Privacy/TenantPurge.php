<?php

declare(strict_types=1);

namespace App\Privacy;

use App\Enums\AuditEvent;
use App\Knowledge\KnowledgePurge;
use App\Knowledge\Vector\IndexUnavailable;
use App\Models\Tenant;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Borra una empresa entera: sus filas, sus archivos, su índice vectorial, las sesiones de su equipo y a la empresa
 * misma (RS-17 y RS-18). Solo se llama desde la consola de quien administra el servidor; ninguna ruta web puede hacerlo.
 *
 * Se puede repetir: primero cae el índice, luego los archivos y al final la base en una sola transacción, así que si algo
 * falla a medias la empresa sigue existiendo y volver a llamarlo termina el trabajo. La auditoría conserva identificadores
 * (el registro no se puede editar), nunca nombres ni correos.
 */
class TenantPurge
{
    public function __construct(
        private readonly KnowledgePurge $knowledge,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Cuántas filas tiene la empresa en cada tabla, sin borrar nada.
     *
     * @return array<string, int>
     */
    public function counts(Tenant $tenant): array
    {
        $counts = [];

        foreach ([...TenantTables::PURGE_ORDER, 'users'] as $table) {
            $counts[$table] = DB::table($table)->where('tenant_id', $tenant->id)->count();
        }

        return $counts;
    }

    /**
     * @return array<string, int> Filas borradas por tabla.
     *
     * @throws IndexUnavailable Si no se puede vaciar el índice vectorial; entonces no se ha borrado nada de la base.
     */
    public function purge(Tenant $tenant): array
    {
        $tenantId = $tenant->id;
        $disks = $this->disksOf($tenantId);

        $this->knowledge->forTenant($tenant);

        foreach ($disks as $disk) {
            Storage::disk($disk)->deleteDirectory("tenants/{$tenantId}");
        }

        return DB::transaction(function () use ($tenantId): array {
            $deleted = [];

            foreach (TenantTables::PURGE_ORDER as $table) {
                $deleted[$table] = DB::table($table)->where('tenant_id', $tenantId)->delete();
            }

            $team = DB::table('users')->where('tenant_id', $tenantId);
            DB::table('sessions')->whereIn('user_id', (clone $team)->select('id'))->delete();
            DB::table('password_reset_tokens')->whereIn('email', (clone $team)->select('email'))->delete();
            $deleted['users'] = $team->delete();

            Tenant::query()->whereKey($tenantId)->delete();

            $this->audit->record(AuditEvent::TenantPurged, null, [
                'tenant_id' => $tenantId,
                'rows' => array_sum($deleted),
                'via' => 'console',
            ]);

            return $deleted;
        });
    }

    /**
     * Los discos donde la empresa pudo guardar archivos: el configurado y los que figuren en sus filas.
     *
     * @return list<string>
     */
    private function disksOf(int $tenantId): array
    {
        $disks = [config()->string('files.disk')];

        foreach (['files', 'ticket_attachments', 'kb_documents'] as $table) {
            foreach (DB::table($table)->where('tenant_id', $tenantId)->distinct()->pluck('disk') as $disk) {
                if (is_string($disk) && $disk !== '') {
                    $disks[] = $disk;
                }
            }
        }

        return array_values(array_unique($disks));
    }
}
