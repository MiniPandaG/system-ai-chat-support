<?php

declare(strict_types=1);

require_once __DIR__.'/../Tickets/Helpers.php';

use App\Enums\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Pide la pantalla como lo hace la recarga en vivo: solo las propiedades indicadas.
 *
 * @param  list<string>  $only
 */
function partialReload(object $test, string $url, array $only, ?Closure $beforeRequest = null): TestResponse
{
    $version = $test->get($url)->viewData('page')['version'];

    if ($beforeRequest !== null) {
        $beforeRequest();
    }

    return $test->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $version,
        'X-Inertia-Partial-Component' => 'tickets/Index',
        'X-Inertia-Partial-Data' => implode(',', $only),
    ])->get($url);
}

it('la recarga del ticket abierto trae el ticket y no repite las consultas de la bandeja', function (): void {
    $tenant = ticketTenant();
    $agent = ticketStaff($tenant);
    $ticket = makeTicket($tenant);
    makeTicket($tenant);
    $this->actingAs($agent);

    $response = partialReload($this, "/tickets/{$ticket->id}", ['selected', 'tag_options'], function (): void {
        DB::flushQueryLog();
        DB::enableQueryLog();
    })->assertOk();
    $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");

    $props = $response->json('props');
    expect($props)->toHaveKeys(['selected', 'tag_options'])
        ->and($props)->not->toHaveKeys(['tickets', 'counts', 'views', 'total', 'next_cursor', 'copy'])
        ->and($props['selected']['id'])->toBe($ticket->id)
        ->and($queries)->not->toContain('priority_rank')
        ->and($queries)->not->toContain('ticket_views');
});

it('la recarga de la bandeja trae lo que la bandeja muestra y no el ticket', function (): void {
    $tenant = ticketTenant();
    $agent = ticketStaff($tenant);
    $ticket = makeTicket($tenant);
    $this->actingAs($agent);

    $props = partialReload($this, "/tickets/{$ticket->id}", ['tickets', 'next_cursor', 'total', 'counts', 'views', 'filter_tags'])->assertOk()->json('props');

    expect($props)->toHaveKeys(['tickets', 'next_cursor', 'total', 'counts', 'views', 'filter_tags'])
        ->and($props)->not->toHaveKey('selected')
        ->and($props['total'])->toBe(1);
});

it('la primera carga sigue trayendo todo', function (): void {
    $tenant = ticketTenant();
    $agent = ticketStaff($tenant);
    $ticket = makeTicket($tenant);

    $props = $this->actingAs($agent)->get("/tickets/{$ticket->id}")->assertOk()->viewData('page')['props'];

    expect($props)->toHaveKeys(['copy', 'tickets', 'next_cursor', 'total', 'filters', 'counts', 'views', 'filter_tags', 'selected', 'assignees', 'tag_options', 'can']);
});

it('un observador recarga el ticket y sigue sin recibir las notas', function (): void {
    $tenant = ticketTenant();
    $agent = ticketStaff($tenant);
    $observer = ticketStaff($tenant, Role::Observer);
    $ticket = makeTicket($tenant);
    makeNote($ticket, $agent, ['body' => 'Nota que el observador no debe ver']);
    $this->actingAs($observer);

    $response = partialReload($this, "/tickets/{$ticket->id}", ['selected', 'tag_options'])->assertOk();

    expect(collect($response->json('props.selected.thread'))->pluck('kind')->unique()->all())->toBe(['message'])
        ->and($response->getContent())->not->toContain('Nota que el observador');
});

it('la recarga de un ticket de otra empresa da 404 igual que la pantalla completa', function (): void {
    $agent = ticketStaff(ticketTenant());
    $foreign = makeTicket(ticketTenant());
    $this->actingAs($agent);

    $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'tickets/Index', 'X-Inertia-Partial-Data' => 'selected'])
        ->get("/tickets/{$foreign->id}")
        ->assertNotFound();
});
