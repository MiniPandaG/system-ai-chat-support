<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Models\Tenant;
use App\Models\User;
use App\Tickets\TicketPriority;
use App\Tickets\TicketStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * RNF-08: la bandeja carga en menos de 2 segundos con 10 000 tickets. Los datos son inventados y se insertan por
 * lotes dentro de la transacción de la prueba, así que desaparecen al terminar.
 */

const PERF_TICKETS = 10000;

/** @return array{0: Tenant, 1: int} la empresa y el identificador de una persona que atiende */
function bigInbox(int $count = PERF_TICKETS): array
{
    $tenant = ticketTenant();
    $agent = ticketStaff($tenant);
    $other = ticketStaff($tenant);

    $customers = [];
    foreach (range(1, 200) as $i) {
        $customers[] = ['tenant_id' => $tenant->id, 'name' => "Cliente {$i}", 'email' => "cliente{$i}@ejemplo-prueba.com", 'created_at' => now(), 'updated_at' => now()];
    }
    DB::table('customers')->insert($customers);
    $customerIds = DB::table('customers')->where('tenant_id', $tenant->id)->pluck('id')->all();

    $statuses = array_map(static fn (TicketStatus $status): string => $status->value, TicketStatus::cases());
    $priorities = array_map(static fn (TicketPriority $priority): string => $priority->value, TicketPriority::cases());
    $people = [$agent->id, $other->id, null];
    $base = Carbon::parse('2026-01-01 00:00:00');

    foreach (array_chunk(range(1, $count), 1000) as $chunk) {
        $rows = [];
        foreach ($chunk as $i) {
            $moment = $base->copy()->addMinutes($i * 7);
            $rows[] = [
                'tenant_id' => $tenant->id,
                'number' => $i,
                'customer_id' => $customerIds[$i % 200],
                'subject' => "Consulta número {$i} sobre el servicio",
                'status' => $statuses[$i % 4],
                'priority' => $priorities[($i * 3) % 4],
                'channel' => 'panel',
                'assigned_to' => $people[$i % 3],
                'last_message_at' => $moment,
                'created_at' => $moment,
                'updated_at' => $moment,
            ];
        }
        DB::table('tickets')->insert($rows);
    }

    $tenant->forceFill(['last_ticket_number' => $count])->save();

    return [$tenant, $agent->id];
}

it('con 10 000 tickets la bandeja carga en menos de 2 segundos, con filtros y con orden', function (): void {
    [$tenant, $agentId] = bigInbox();
    $agent = User::query()->findOrFail($agentId);
    $this->actingAs($agent)->get('/tickets')->assertOk();

    foreach (['', '?scope=mine', '?scope=unassigned', '?status[]=open&priority[]=low', '?sort=priority', '?sort=oldest', '?from=2026-02-01&to=2026-03-01', "?agent={$agentId}"] as $query) {
        $started = microtime(true);
        $response = $this->actingAs($agent)->get("/tickets{$query}")->assertOk();
        $elapsed = microtime(true) - $started;

        expect($elapsed)->toBeLessThan(2.0, "La bandeja tardó {$elapsed} s con «{$query}».");
        expect(inboxRows($response))->not->toBeEmpty();
    }
});

it('con 10 000 tickets los tramos siguientes también responden rápido y recorren todo sin repetir', function (): void {
    [, $agentId] = bigInbox();
    $agent = User::query()->findOrFail($agentId);
    $seen = [];
    $cursor = null;
    $started = microtime(true);
    $pages = 0;

    do {
        $url = $cursor === null ? '/tickets/page?sort=priority' : '/tickets/page?sort=priority&cursor='.urlencode($cursor);
        $response = $this->actingAs($agent)->getJson($url)->assertOk();
        foreach ($response->json('data') as $row) {
            $seen[$row['id']] = true;
        }
        $cursor = $response->json('next_cursor');
        $pages++;
    } while ($cursor !== null && $pages < 400);

    expect($pages)->toBe(334)->and(count($seen))->toBe(PERF_TICKETS)
        ->and((microtime(true) - $started) / $pages)->toBeLessThan(0.5);
})->skip(! (bool) getenv('INBOX_FULL_WALK'), 'Recorrer los 334 tramos es lento; se activa con INBOX_FULL_WALK=1.');

it('los contadores y el total con 10 000 tickets no tardan', function (): void {
    [, $agentId] = bigInbox();
    $agent = User::query()->findOrFail($agentId);
    $started = microtime(true);

    $response = $this->actingAs($agent)->get('/tickets')->assertOk();

    expect(microtime(true) - $started)->toBeLessThan(2.0)
        ->and(inboxProp($response, 'counts.all'))->toBe(PERF_TICKETS)
        ->and(inboxProp($response, 'total'))->toBe(PERF_TICKETS)
        ->and(inboxProp($response, 'counts.mine'))->toBeGreaterThan(0)
        ->and(inboxProp($response, 'counts.unassigned'))->toBeGreaterThan(0);
});

it('una lista de 10 000 tickets usa las mismas consultas que una de 30', function (): void {
    [, $agentId] = bigInbox(30);
    $agent = User::query()->findOrFail($agentId);
    $this->actingAs($agent)->get('/tickets');
    DB::enableQueryLog();
    $this->actingAs($agent)->get('/tickets')->assertOk();
    $small = count(DB::getQueryLog());
    DB::disableQueryLog();

    [, $bigAgentId] = bigInbox();
    $bigAgent = User::query()->findOrFail($bigAgentId);
    $this->actingAs($bigAgent)->get('/tickets');
    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($bigAgent)->get('/tickets')->assertOk();
    $big = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($big)->toBe($small);
});

it('los filtros usan los índices: la lista pendiente de una persona no recorre toda la tabla', function (): void {
    [$tenant, $agentId] = bigInbox();

    $plan = DB::select('explain select * from tickets where tenant_id = ? and status in (?, ?) and assigned_to = ? order by last_message_at desc limit 30', [$tenant->id, 'open', 'waiting_customer', $agentId]);

    expect($plan[0]->key)->not->toBeNull()->and($plan[0]->type)->not->toBe('ALL');
});
