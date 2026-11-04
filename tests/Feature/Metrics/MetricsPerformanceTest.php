<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Enums\Role;
use App\Metrics\Range;
use App\Metrics\SupportMetrics;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * «Consultas eficientes» (ficha de M12): con 10 000 tickets el panel hace las mismas consultas que con unos pocos y responde en
 * menos de 2 segundos. Los datos son inventados y se insertan por lotes dentro de la transacción de la prueba.
 *
 * Lo que demuestra la eficiencia es el número de consultas y el uso de índices, que no dependen de la máquina. El reloj sí:
 * en la integración continua (equipos compartidos y arranque en frío) un tope de 2 s falló una vez sin que nada hubiera
 * empeorado. Por eso aquí el tope solo atrapa un desastre (más de 10 s); los 2 s de RNF-08 se miden en una máquina sin carga
 * con scripts/load (ver entregas/m13).
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-04 15:00:00 UTC');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** Una empresa con `$count` tickets repartidos en 30 días, la mitad con primera respuesta y una parte resuelta. */
function metricsBigTenant(int $count): Tenant
{
    $tenant = ticketTenant();
    DB::table('customers')->insert(['tenant_id' => $tenant->id, 'name' => 'Cliente', 'email' => null, 'created_at' => now(), 'updated_at' => now()]);
    $customer = (int) DB::table('customers')->where('tenant_id', $tenant->id)->value('id');
    $base = Carbon::parse('2026-10-06 06:00:00', 'UTC');

    foreach (array_chunk(range(1, $count), 1000) as $chunk) {
        $rows = [];

        foreach ($chunk as $i) {
            $created = $base->copy()->addMinutes($i * 4);
            $rows[] = [
                'tenant_id' => $tenant->id,
                'number' => $i,
                'customer_id' => $customer,
                'subject' => "Consulta {$i}",
                'status' => $i % 3 === 0 ? 'resolved' : 'open',
                'priority' => 'normal',
                'channel' => 'panel',
                'last_message_at' => $created,
                'first_response_at' => $i % 2 === 0 ? $created->copy()->addMinutes($i % 90) : null,
                'resolved_at' => $i % 3 === 0 ? $created->copy()->addHours($i % 48) : null,
                'created_at' => $created,
                'updated_at' => $created,
            ];
        }

        DB::table('tickets')->insert($rows);
    }

    return $tenant;
}

/** @return array{0: int, 1: float} Cuántas consultas hizo el informe y cuántos segundos tardó. */
function metricsCost(Tenant $tenant): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $started = microtime(true);

    app(TenantContext::class)->run($tenant, function (): void {
        $metrics = app(SupportMetrics::class);
        $metrics->report(Range::make('30d'));
        $metrics->comments(Range::make('30d'), null);
    });

    $seconds = microtime(true) - $started;
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    return [$queries, $seconds];
}

it('con 10 000 tickets el informe hace las mismas consultas que con 10 y no se va de tiempo', function (): void {
    [$smallQueries] = metricsCost(metricsBigTenant(10));
    [$bigQueries, $seconds] = metricsCost(metricsBigTenant(10000));

    expect($bigQueries)->toBe($smallQueries)->and($seconds)->toBeLessThan(10.0);
});

it('la pantalla completa también responde con 10 000 tickets sin irse de tiempo', function (): void {
    $tenant = metricsBigTenant(10000);
    $admin = ticketStaff($tenant, Role::Admin);

    $started = microtime(true);
    test()->actingAs($admin)->get('/metrics')->assertOk();

    expect(microtime(true) - $started)->toBeLessThan(10.0);
});

it('las consultas de rango usan los índices por empresa y fecha', function (): void {
    $tenant = metricsBigTenant(2000);
    $from = '2026-10-06 05:00:00';
    $to = '2026-11-05 04:59:59';

    $created = DB::select('EXPLAIN SELECT created_at, first_response_at FROM tickets WHERE tenant_id = ? AND created_at BETWEEN ? AND ? ORDER BY created_at DESC LIMIT 50000', [$tenant->id, $from, $to]);
    $resolved = DB::select('EXPLAIN SELECT created_at, resolved_at FROM tickets WHERE tenant_id = ? AND resolved_at BETWEEN ? AND ? ORDER BY resolved_at DESC LIMIT 50000', [$tenant->id, $from, $to]);

    expect($created[0]->key)->toBe('tickets_tenant_id_created_at_index')->and($resolved[0]->key)->toBe('tickets_tenant_id_resolved_at_index');
});
