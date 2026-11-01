<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Enums\Role;
use App\Models\Plan;
use App\Models\Ticket;
use App\Plans\Limit;
use App\Plans\PlanLimits;
use App\Tenancy\TenantContext;
use App\Tickets\TicketStatus;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

function createTicketAs($test, $agent, int $i): TestResponse
{
    return $test->actingAs($agent)->post('/tickets', newTicketPayload(['customer_name' => "Cliente {$i}", 'customer_email' => '', 'subject' => "Consulta {$i}"]));
}

afterEach(function (): void {
    Carbon::setTestNow();
});

it('al llegar al tope del plan bloquea con un mensaje claro y no crea nada ni gasta número', function (): void {
    Plan::query()->where('slug', 'inicial')->update(['max_tickets_per_month' => 2]);
    $tenant = ticketTenant('inicial');
    $agent = ticketStaff($tenant);

    createTicketAs($this, $agent, 1)->assertRedirect();
    createTicketAs($this, $agent, 2)->assertRedirect();
    $response = createTicketAs($this, $agent, 3);

    $response->assertSessionHasErrors('limit');
    expect(session('errors')?->first('limit'))->toContain('Inicial')->toContain('2 tickets al mes')->toContain('ya usaste 2')
        ->and(ticketsOf($tenant))->toHaveCount(2)
        ->and($tenant->fresh()?->last_ticket_number)->toBe(2);
});

it('los tickets resueltos o cerrados siguen contando en el mes', function (): void {
    Plan::query()->where('slug', 'inicial')->update(['max_tickets_per_month' => 2]);
    $tenant = ticketTenant('inicial');
    $agent = ticketStaff($tenant);
    makeTicket($tenant, ['status' => TicketStatus::Closed]);
    makeTicket($tenant, ['status' => TicketStatus::Resolved]);

    createTicketAs($this, $agent, 1)->assertSessionHasErrors('limit');
});

it('el mes siguiente el contador vuelve a cero', function (): void {
    Carbon::setTestNow('2026-03-15 10:00:00');
    Plan::query()->where('slug', 'inicial')->update(['max_tickets_per_month' => 1]);
    $tenant = ticketTenant('inicial');
    $agent = ticketStaff($tenant);

    createTicketAs($this, $agent, 1)->assertRedirect();
    createTicketAs($this, $agent, 2)->assertSessionHasErrors('limit');

    Carbon::setTestNow('2026-04-01 00:00:05');
    createTicketAs($this, $agent, 3)->assertRedirect();
    createTicketAs($this, $agent, 4)->assertSessionHasErrors('limit');

    expect(ticketsOf($tenant))->toHaveCount(2);
});

it('solo cuenta los tickets de la propia empresa', function (): void {
    Plan::query()->where('slug', 'inicial')->update(['max_tickets_per_month' => 1]);
    $a = ticketTenant('inicial');
    $b = ticketTenant('inicial');
    makeTicket($b);

    createTicketAs($this, ticketStaff($a), 1)->assertRedirect();
});

it('un plan sin tope de tickets no bloquea', function (): void {
    Plan::query()->where('slug', 'inicial')->update(['max_tickets_per_month' => null]);
    $tenant = ticketTenant('inicial');
    $agent = ticketStaff($tenant);

    foreach (range(1, 5) as $i) {
        createTicketAs($this, $agent, $i)->assertRedirect();
    }

    expect(ticketsOf($tenant))->toHaveCount(5);
});

it('bajar de plan no borra ni bloquea los tickets que ya existen: solo impide abrir más', function (): void {
    $tenant = ticketTenant('negocio');
    $agent = ticketStaff($tenant);
    foreach (range(1, 3) as $i) {
        createTicketAs($this, $agent, $i)->assertRedirect();
    }
    Plan::query()->where('slug', 'inicial')->update(['max_tickets_per_month' => 2]);

    $this->artisan('plans:assign', ['tenant' => $tenant->id, 'plan' => 'inicial', '--no-interaction' => true])->assertSuccessful();

    createTicketAs($this, $agent, 4)->assertSessionHasErrors('limit');
    $this->actingAs($agent)->get('/tickets')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('tickets', 3));
});

it('el servicio de límites mide los tickets del mes y la pantalla de plan los muestra', function (): void {
    $tenant = ticketTenant('equipo');
    makeTicket($tenant);
    makeTicket($tenant);
    makeTicket(ticketTenant('equipo'));
    $old = makeTicket($tenant);
    app(TenantContext::class)->run($tenant, fn () => Ticket::query()->whereKey($old->id)->update(['created_at' => now()->subMonths(2)]));

    $limits = app(PlanLimits::class);

    expect($limits->usage($tenant, Limit::TicketsPerMonth))->toBe(2)
        ->and($limits->remaining($tenant, Limit::TicketsPerMonth))->toBe(998)
        ->and($limits->isOver($tenant, Limit::TicketsPerMonth))->toBeFalse();

    $admin = ticketStaff($tenant, Role::Admin);

    $this->actingAs($admin)->get('/settings/plan')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('limits.2.key', 'tickets_per_month')->where('limits.2.measured', true)->where('limits.2.used', 2)->where('limits.2.remaining', 998));
});

it('el mensaje del límite se muestra al agente sin revelar nada de otras empresas', function (): void {
    Plan::query()->where('slug', 'inicial')->update(['max_tickets_per_month' => 1]);
    $tenant = ticketTenant('inicial');
    makeTicket(ticketTenant('inicial'));
    $agent = ticketStaff($tenant);
    createTicketAs($this, $agent, 1)->assertRedirect();

    $message = (string) createTicketAs($this, $agent, 2)->assertSessionHasErrors('limit')->baseResponse->getSession()?->get('errors')?->first('limit');

    expect($message)->toContain('1 ticket')->and($message)->not->toContain('2');
});
