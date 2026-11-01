<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Enums\Role;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Tenancy\TenantContext;
use App\Tickets\MessageAuthor;
use App\Tickets\TicketPriority;
use App\Tickets\TicketStatus;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

it('la bandeja lista los tickets del más reciente al más antiguo con lo que muestra cada fila', function (): void {
    $tenant = ticketTenant();
    $agent = ticketStaff($tenant);
    $customer = app(TenantContext::class)->run($tenant, fn () => Customer::factory()->create(['name' => 'Marisol Quintero']));
    $old = makeTicket($tenant, ['last_message_at' => now()->subDays(2), 'subject' => 'Antiguo'], $customer);
    $new = makeTicket($tenant, ['last_message_at' => now(), 'subject' => 'Reciente', 'priority' => TicketPriority::Urgent, 'status' => TicketStatus::WaitingCustomer, 'assigned_to' => $agent->id], $customer);

    $this->actingAs($agent)->get('/tickets')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('tickets/Index')
        ->where('copy.title', 'Bandeja')
        ->where('tickets.0.id', $new->id)
        ->where('tickets.0.number', $new->number)
        ->where('tickets.0.subject', 'Reciente')
        ->where('tickets.0.status', 'waiting_customer')
        ->where('tickets.0.priority', 'urgent')
        ->where('tickets.0.customer_name', 'Marisol Quintero')
        ->where('tickets.0.assignee_name', $agent->name)
        ->where('tickets.1.id', $old->id)
        ->where('tickets.1.assignee_name', null)
        ->where('selected', null)
        ->where('can.create', true));
});

it('la primera carga de la lista trae como máximo 30 tickets', function (): void {
    $tenant = ticketTenant();
    foreach (range(1, 55) as $i) {
        makeTicket($tenant);
    }

    $this->actingAs(ticketStaff($tenant))->get('/tickets')->assertInertia(fn (AssertableInertia $page) => $page->has('tickets', 30)->where('total', 55)->whereNot('next_cursor', null));
});

it('abrir un ticket muestra su cabecera y su conversación en orden', function (): void {
    $tenant = ticketTenant();
    $agent = ticketStaff($tenant);
    $customer = app(TenantContext::class)->run($tenant, fn () => Customer::factory()->create(['name' => 'Marisol Quintero', 'email' => 'marisol@cliente-ejemplo.com']));
    $ticket = makeTicket($tenant, ['assigned_to' => $agent->id], $customer);
    app(TenantContext::class)->run($tenant, function () use ($ticket, $agent): void {
        $reply = new TicketMessage(['body' => 'Ya lo estamos revisando.']);
        $reply->forceFill(['ticket_id' => $ticket->id, 'author' => MessageAuthor::Agent, 'user_id' => $agent->id])->save();
    });

    $this->actingAs($agent)->get("/tickets/{$ticket->id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('selected.id', $ticket->id)
        ->where('selected.number', $ticket->number)
        ->where('selected.status', 'open')
        ->where('selected.channel', 'panel')
        ->where('selected.assigned_to', $agent->id)
        ->where('selected.customer', ['name' => 'Marisol Quintero', 'email' => 'marisol@cliente-ejemplo.com'])
        ->has('selected.thread', 2)
        ->where('selected.thread.0.author', 'customer')
        ->where('selected.thread.0.author_name', 'Marisol Quintero')
        ->where('selected.thread.1.author', 'agent')
        ->where('selected.thread.1.author_name', $agent->name)
        ->where('selected.thread.1.body', 'Ya lo estamos revisando.')
        ->where('can.update', true));
});

it('un observador ve el ticket pero la pantalla le dice que no puede modificarlo', function (): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant);

    $this->actingAs(ticketStaff($tenant, Role::Observer))->get("/tickets/{$ticket->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('can.create', false)->where('can.update', false)->has('selected.thread', 1));
});

it('quien puede ser asignado: administradores y agentes activos de la empresa, y quien ya lo tenía aunque se desactive', function (): void {
    $tenant = ticketTenant();
    $viewer = ticketStaff($tenant, Role::Admin);
    $agent = ticketStaff($tenant);
    ticketStaff($tenant, Role::Observer);
    ticketStaff($tenant, Role::Agent, disabled: true);
    ticketStaff(ticketTenant());
    $former = ticketStaff($tenant);
    $ticket = makeTicket($tenant, ['assigned_to' => $former->id]);
    $former->forceFill(['disabled_at' => now()])->save();

    $this->actingAs($viewer)->get("/tickets/{$ticket->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('assignees', fn ($options): bool => collect($options)->pluck('id')->sort()->values()->all() === collect([$viewer->id, $agent->id, $former->id])->sort()->values()->all()
            && collect($options)->firstWhere('id', $former->id)['active'] === false
            && collect($options)->firstWhere('id', $agent->id)['active'] === true));
});

it('la bandeja no hace más consultas con más tickets (sin N+1)', function (): void {
    $queries = function (int $tickets): int {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        foreach (range(1, $tickets) as $i) {
            makeTicket($tenant, ['assigned_to' => $agent->id]);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        test()->actingAs($agent)->get('/tickets')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    expect($queries(20))->toBe($queries(3));
});

it('el ticket abierto tampoco hace más consultas con más mensajes', function (): void {
    $queries = function (int $messages): int {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $ticket = makeTicket($tenant);
        app(TenantContext::class)->run($tenant, function () use ($ticket, $agent, $messages): void {
            foreach (range(1, $messages) as $i) {
                $message = new TicketMessage(['body' => "Respuesta {$i}"]);
                $message->forceFill(['ticket_id' => $ticket->id, 'author' => MessageAuthor::Agent, 'user_id' => $agent->id])->save();
            }
        });

        DB::enableQueryLog();
        DB::flushQueryLog();
        test()->actingAs($agent)->get("/tickets/{$ticket->id}")->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    expect($queries(25))->toBe($queries(2));
});

it('los tickets de la lista no traen el cuerpo de los mensajes', function (): void {
    $tenant = ticketTenant();
    makeTicket($tenant);

    $this->actingAs(ticketStaff($tenant))->get('/tickets')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('tickets.0', fn ($row): bool => ! isset($row['messages']) && ! isset($row['body']) && ! isset($row['email'])));
});

it('una bandeja vacía llega vacía y sin ticket seleccionado', function (): void {
    $this->actingAs(ticketStaff(ticketTenant()))->get('/tickets')->assertInertia(fn (AssertableInertia $page) => $page->has('tickets', 0)->where('selected', null));
});

it('el modelo de ticket solo deja rellenar el asunto', function (): void {
    expect((new Ticket)->getFillable())->toBe(['subject'])
        ->and((new TicketMessage)->getFillable())->toBe(['body'])
        ->and((new Customer)->getFillable())->toBe(['name', 'email']);
});
