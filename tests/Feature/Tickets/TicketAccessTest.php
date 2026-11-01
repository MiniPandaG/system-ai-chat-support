<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Enums\Role;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use App\Tenancy\TenancyViolation;
use App\Tenancy\TenantContext;
use Inertia\Testing\AssertableInertia;

it('matriz de permisos: quién puede ver, crear y modificar', function (Role $role, int $list, int $create, int $update): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant);
    $user = ticketStaff($tenant, $role);

    $this->actingAs($user)->get('/tickets')->assertStatus($list);
    $this->actingAs($user)->get("/tickets/{$ticket->id}")->assertStatus($list);
    $this->actingAs($user)->post('/tickets', newTicketPayload())->assertStatus($create);
    $this->actingAs($user)->patch("/tickets/{$ticket->id}", ['priority' => 'high'])->assertStatus($update);
})->with([
    'administrador' => [Role::Admin, 200, 302, 302],
    'agente' => [Role::Agent, 200, 302, 302],
    'observador' => [Role::Observer, 200, 403, 403],
]);

it('un observador no cambia nada aunque lo intente', function (): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant);

    $this->actingAs(ticketStaff($tenant, Role::Observer))->patch("/tickets/{$ticket->id}", ['status' => 'closed', 'assigned_to' => null])->assertForbidden();
    $this->actingAs(ticketStaff($tenant, Role::Observer))->post('/tickets', newTicketPayload())->assertForbidden();

    expect(app(TenantContext::class)->run($tenant, fn () => Ticket::query()->count()))->toBe(1)
        ->and(app(TenantContext::class)->run($tenant, fn () => Ticket::query()->findOrFail($ticket->id)->status->value))->toBe('open');
});

it('el personal de la plataforma no entra a la bandeja ni a los clientes', function (): void {
    $staff = User::factory()->create(['is_platform_admin' => true]);
    $staff->forceFill(['two_factor_secret' => encrypt('S'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()])->save();
    $ticket = makeTicket(ticketTenant());

    $this->actingAs($staff)->get('/tickets')->assertForbidden();
    $this->actingAs($staff)->get("/tickets/{$ticket->id}")->assertNotFound();
    $this->actingAs($staff)->post('/tickets', newTicketPayload())->assertForbidden();
    $this->actingAs($staff)->get('/customers/search?q=ab')->assertForbidden();
});

it('un visitante y un usuario desactivado van al inicio de sesión', function (): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant);

    $this->get('/tickets')->assertRedirect('/login');
    $this->get("/tickets/{$ticket->id}")->assertRedirect('/login');
    $this->post('/tickets', newTicketPayload())->assertRedirect('/login');
    $this->patch("/tickets/{$ticket->id}", ['priority' => 'high'])->assertRedirect('/login');
    $this->getJson('/customers/search?q=ab')->assertUnauthorized();

    $this->actingAs(ticketStaff($tenant, Role::Agent, disabled: true))->get('/tickets')->assertRedirect(route('login'));
});

it('un administrador sin 2FA pasa primero por la pantalla de seguridad', function (): void {
    $tenant = ticketTenant();
    $admin = User::factory()->forTenant($tenant, Role::Admin)->create();

    $this->actingAs($admin)->get('/tickets')->assertRedirect(route('security'));
});

it('el ticket abierto pide un identificador numérico', function (): void {
    $this->actingAs(ticketStaff(ticketTenant()))->get('/tickets/abc')->assertNotFound();
});

it('la entrada del panel lleva al equipo a la bandeja y al personal de la plataforma a su cuenta', function (): void {
    $this->actingAs(ticketStaff(ticketTenant()))->get('/')->assertRedirect(route('tickets'));
    $this->actingAs(User::factory()->create(['is_platform_admin' => true]))->get('/')->assertRedirect(route('account'));
    $this->get('/')->assertRedirect(route('account'));
});

it('los datos compartidos dicen quién ve la bandeja', function (Role $role, bool $expected): void {
    $tenant = ticketTenant();
    $user = ticketStaff($tenant, $role);

    $this->actingAs($user)->get('/tickets')->assertInertia(fn (AssertableInertia $page) => $page->where('auth.can.tickets', $expected)->where('can.create', $role !== Role::Observer));
})->with([
    'administrador' => [Role::Admin, true],
    'agente' => [Role::Agent, true],
    'observador' => [Role::Observer, true],
]);

describe('aislamiento entre empresas', function (): void {
    it('otra empresa recibe 404 al pedir o cambiar un ticket', function (): void {
        $ticket = makeTicket(ticketTenant());
        $intruder = ticketStaff(ticketTenant());

        $this->assertOtherTenantGets404($intruder, 'GET', "/tickets/{$ticket->id}");
        $this->assertOtherTenantGets404($intruder, 'PATCH', "/tickets/{$ticket->id}", ['priority' => 'urgent']);
    });

    it('un administrador de otra empresa tampoco, ni siquiera con el identificador correcto', function (): void {
        $ticket = makeTicket(ticketTenant());
        $intruder = ticketStaff(ticketTenant(), Role::Admin);

        $this->assertOtherTenantGets404($intruder, 'GET', "/tickets/{$ticket->id}");
        $this->assertOtherTenantGets404($intruder, 'PATCH', "/tickets/{$ticket->id}", ['status' => 'closed']);
    });

    it('la bandeja solo muestra los tickets de la propia empresa', function (): void {
        $a = ticketTenant();
        $b = ticketTenant();
        $own = makeTicket($a);
        makeTicket($b);
        makeTicket($b);

        $this->actingAs(ticketStaff($a))->get('/tickets')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('tickets', 1)
            ->where('tickets.0.id', $own->id));
    });

    it('no se puede abrir un ticket a nombre de un cliente de otra empresa ni leerlo', function (): void {
        $a = ticketTenant();
        $foreignCustomer = app(TenantContext::class)->run(ticketTenant(), fn () => Customer::factory()->create());

        $this->actingAs(ticketStaff($a))->post('/tickets', newTicketPayload(['customer_id' => $foreignCustomer->id]))->assertSessionHasErrors('customer_id');

        expect(ticketsOf($a))->toHaveCount(0);
    });

    it('sin empresa en contexto no se lee ningún ticket', function (): void {
        makeTicket(ticketTenant());

        expect(Ticket::query()->count())->toBe(0);
    });

    it('un ticket no se puede mover a otra empresa', function (): void {
        $a = ticketTenant();
        $b = ticketTenant();
        $ticket = makeTicket($a);

        expect(fn () => app(TenantContext::class)->run($a, function () use ($ticket, $b): void {
            $ticket->forceFill(['tenant_id' => $b->id])->save();
        }))->toThrow(TenancyViolation::class);
    });
});
