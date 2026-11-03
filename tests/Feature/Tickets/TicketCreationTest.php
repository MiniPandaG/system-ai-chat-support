<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Enums\AuditEvent;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Tenancy\TenantContext;
use App\Tickets\Channel;
use App\Tickets\MessageAuthor;
use App\Tickets\TicketPriority;
use App\Tickets\TicketStatus;
use Inertia\Testing\AssertableInertia;

it('un agente abre un ticket con un cliente nuevo y su primer mensaje', function (): void {
    $tenant = ticketTenant();
    $agent = ticketStaff($tenant);

    $response = $this->actingAs($agent)->post('/tickets', newTicketPayload(['priority' => 'high']));

    $ticket = ticketsOf($tenant)->sole();
    $response->assertRedirect(route('tickets.show', $ticket))->assertSessionHas('status', 'Ticket #1 creado.');

    expect($ticket->number)->toBe(1)
        ->and($ticket->subject)->toBe('No puedo entrar a mi cuenta')
        ->and($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->priority)->toBe(TicketPriority::High)
        ->and($ticket->channel)->toBe(Channel::Panel)
        ->and($ticket->created_by)->toBe($agent->id)
        ->and($ticket->assigned_to)->toBeNull()
        ->and($ticket->tenant_id)->toBe($tenant->id);

    app(TenantContext::class)->run($tenant, function () use ($ticket, $agent): void {
        $customer = Customer::query()->sole();
        $message = TicketMessage::query()->sole();

        expect($customer->name)->toBe('Marisol Quintero')
            ->and($customer->email)->toBe('marisol@cliente-ejemplo.com')
            ->and($ticket->customer_id)->toBe($customer->id)
            ->and($message->ticket_id)->toBe($ticket->id)
            ->and($message->author)->toBe(MessageAuthor::Customer)
            ->and($message->user_id)->toBe($agent->id)
            ->and($message->body)->toContain("\nYa probé restablecerla.");
    });
});

it('un administrador también abre tickets', function (): void {
    $tenant = ticketTenant();

    $this->actingAs(ticketStaff($tenant, Role::Admin))->post('/tickets', newTicketPayload())->assertRedirect();

    expect(ticketsOf($tenant))->toHaveCount(1);
});

it('el correo del cliente se guarda en minúsculas y puede quedar vacío', function (): void {
    $tenant = ticketTenant();
    $agent = ticketStaff($tenant);

    $this->actingAs($agent)->post('/tickets', newTicketPayload(['customer_email' => '  Marisol@Cliente-Ejemplo.COM ']))->assertRedirect();
    $this->actingAs($agent)->post('/tickets', newTicketPayload(['customer_name' => 'Sin correo', 'customer_email' => '']))->assertRedirect();

    app(TenantContext::class)->run($tenant, function (): void {
        expect(Customer::query()->orderBy('id')->pluck('email')->all())->toBe(['marisol@cliente-ejemplo.com', null]);
    });
});

it('dos clientes sin correo conviven; con correo no se repite dentro de la empresa pero sí entre empresas', function (): void {
    $a = ticketTenant();
    $b = ticketTenant();
    $agentA = ticketStaff($a);

    $this->actingAs($agentA)->post('/tickets', newTicketPayload(['customer_email' => '']))->assertRedirect();
    $this->actingAs($agentA)->post('/tickets', newTicketPayload(['customer_name' => 'Otro', 'customer_email' => '']))->assertRedirect();
    $this->actingAs($agentA)->post('/tickets', newTicketPayload())->assertRedirect();
    $this->actingAs($agentA)->post('/tickets', newTicketPayload(['customer_name' => 'Repetido']))
        ->assertSessionHasErrors(['customer_email' => 'Ya hay un cliente con ese correo en tu empresa. Búscalo en «Cliente existente».']);

    $this->actingAs(ticketStaff($b))->post('/tickets', newTicketPayload())->assertRedirect();
});

it('con un cliente existente no crea otro y deja el nombre y el correo como estaban', function (): void {
    $tenant = ticketTenant();
    $customer = app(TenantContext::class)->run($tenant, fn () => Customer::factory()->create(['name' => 'Cliente Previo', 'email' => 'previo@cliente-ejemplo.com']));

    $this->actingAs(ticketStaff($tenant))->post('/tickets', newTicketPayload(['customer_id' => $customer->id, 'customer_name' => null, 'customer_email' => null]))->assertRedirect();

    app(TenantContext::class)->run($tenant, function () use ($customer): void {
        expect(Customer::query()->count())->toBe(1)
            ->and(Ticket::query()->sole()->customer_id)->toBe($customer->id);
    });
});

it('los números de ticket siguen un contador por empresa, sin huecos ni cruces', function (): void {
    $a = ticketTenant();
    $b = ticketTenant();
    $agentA = ticketStaff($a);
    $agentB = ticketStaff($b);

    foreach (range(1, 3) as $i) {
        $this->actingAs($agentA)->post('/tickets', newTicketPayload(['customer_name' => "Cliente A{$i}", 'customer_email' => '']))->assertRedirect();
    }
    $this->actingAs($agentB)->post('/tickets', newTicketPayload())->assertRedirect();

    expect(ticketsOf($a)->pluck('number')->all())->toBe([1, 2, 3])
        ->and(ticketsOf($b)->pluck('number')->all())->toBe([1])
        ->and($a->fresh()?->last_ticket_number)->toBe(3);
});

it('un intento que no valida no gasta número ni crea nada', function (): void {
    $tenant = ticketTenant();

    $this->actingAs(ticketStaff($tenant))->post('/tickets', newTicketPayload(['subject' => '']))->assertSessionHasErrors('subject');

    expect(ticketsOf($tenant))->toHaveCount(0)
        ->and($tenant->fresh()?->last_ticket_number)->toBe(0);
});

it('asigna el ticket a un agente activo de la empresa y lo audita sin datos personales', function (): void {
    $tenant = ticketTenant();
    $creator = ticketStaff($tenant);
    $assignee = ticketStaff($tenant, Role::Admin);

    $this->actingAs($creator)->post('/tickets', newTicketPayload(['assigned_to' => $assignee->id]))->assertRedirect();

    $ticket = ticketsOf($tenant)->sole();
    $created = AuditLog::query()->where('event', AuditEvent::TicketCreated->value)->latest('id')->firstOrFail();
    $assigned = AuditLog::query()->where('event', AuditEvent::TicketAssigned->value)->latest('id')->firstOrFail();

    expect($ticket->assigned_to)->toBe($assignee->id)
        ->and($created->context)->toEqual(['ticket_id' => $ticket->id, 'number' => 1, 'via' => 'panel'])
        ->and($assigned->context)->toEqual(['ticket_id' => $ticket->id, 'to' => $assignee->id])
        ->and($created->user_id)->toBe($creator->id)
        ->and((string) json_encode($created->context).(string) json_encode($assigned->context))->not->toContain('marisol')->not->toContain('Marisol')->not->toContain('contraseña');
});

it('no se puede asignar a quien no atiende: observador, desactivado, de otra empresa o inexistente', function (string $case): void {
    $tenant = ticketTenant();
    $agent = ticketStaff($tenant);
    $target = match ($case) {
        'observador' => ticketStaff($tenant, Role::Observer)->id,
        'desactivado' => ticketStaff($tenant, Role::Agent, disabled: true)->id,
        'otra empresa' => ticketStaff(ticketTenant())->id,
        default => 999_999,
    };

    $this->actingAs($agent)->post('/tickets', newTicketPayload(['assigned_to' => $target]))
        ->assertSessionHasErrors(['assigned_to' => 'Esa persona no puede atender tickets: elige a un administrador o agente activo.']);

    expect(ticketsOf($tenant))->toHaveCount(0);
})->with(['observador', 'desactivado', 'otra empresa', 'inexistente']);

it('un cliente de otra empresa se rechaza como si no existiera', function (): void {
    $tenant = ticketTenant();
    $foreign = app(TenantContext::class)->run(ticketTenant(), fn () => Customer::factory()->create());

    $this->actingAs(ticketStaff($tenant))->post('/tickets', newTicketPayload(['customer_id' => $foreign->id]))
        ->assertSessionHasErrors(['customer_id' => 'Ese cliente ya no existe en tu empresa. Búscalo de nuevo o crea uno.']);

    expect(ticketsOf($tenant))->toHaveCount(0);
});

it('valida cada campo con un mensaje específico', function (array $override, string $field): void {
    $tenant = ticketTenant();

    $this->actingAs(ticketStaff($tenant))->post('/tickets', newTicketPayload($override))->assertSessionHasErrors($field);

    expect(ticketsOf($tenant))->toHaveCount(0);
})->with([
    'asunto vacío' => [['subject' => ''], 'subject'],
    'asunto de 151 caracteres' => [['subject' => str_repeat('a', 151)], 'subject'],
    'asunto con salto de línea' => [['subject' => "Línea uno\nlínea dos"], 'subject'],
    'asunto con carácter de control' => [['subject' => "Hola\x07"], 'subject'],
    'asunto que no es texto' => [['subject' => ['a']], 'subject'],
    'descripción vacía' => [['description' => ''], 'description'],
    'descripción de 10001 caracteres' => [['description' => str_repeat('a', 10001)], 'description'],
    'descripción con carácter nulo' => [['description' => "Hola\x00mundo"], 'description'],
    'prioridad desconocida' => [['priority' => 'critica'], 'priority'],
    'sin prioridad' => [['priority' => null], 'priority'],
    'sin cliente ni nombre' => [['customer_name' => '', 'customer_email' => ''], 'customer_name'],
    'nombre de 121 caracteres' => [['customer_name' => str_repeat('a', 121)], 'customer_name'],
    'nombre con salto de línea' => [['customer_name' => "Ana\nPérez"], 'customer_name'],
    'correo inválido' => [['customer_email' => 'no-es-un-correo'], 'customer_email'],
    'asignado no numérico' => [['assigned_to' => 'abc'], 'assigned_to'],
]);

it('acepta la descripción con saltos de línea y de 10000 caracteres exactos', function (): void {
    $tenant = ticketTenant();
    $body = str_repeat("línea\n", 1666).'fin';

    $this->actingAs(ticketStaff($tenant))->post('/tickets', newTicketPayload(['description' => $body]))->assertRedirect();

    expect(mb_strlen($body))->toBeLessThanOrEqual(10000)
        ->and(app(TenantContext::class)->run($tenant, fn () => TicketMessage::query()->sole()->body))->toBe($body);
});

it('ignora los campos que el cliente no debe escribir', function (): void {
    $tenant = ticketTenant();
    $other = ticketTenant();
    $agent = ticketStaff($tenant);

    $this->actingAs($agent)->post('/tickets', newTicketPayload([
        'tenant_id' => $other->id,
        'number' => 500,
        'status' => 'closed',
        'channel' => 'whatsapp',
        'created_by' => 999,
        'last_message_at' => '2000-01-01 00:00:00',
        'closed_at' => now()->toDateTimeString(),
    ]))->assertRedirect();

    $ticket = ticketsOf($tenant)->sole();

    expect($ticket->tenant_id)->toBe($tenant->id)
        ->and($ticket->number)->toBe(1)
        ->and($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->channel)->toBe(Channel::Panel)
        ->and($ticket->created_by)->toBe($agent->id)
        ->and($ticket->closed_at)->toBeNull()
        ->and($ticket->last_message_at->isToday())->toBeTrue()
        ->and(ticketsOf($other))->toHaveCount(0);
});

it('guarda el texto tal cual y lo entrega como texto, sin interpretarlo', function (): void {
    $tenant = ticketTenant();
    $agent = ticketStaff($tenant);
    $payload = '<img src=x onerror="alert(1)"><script>alert(2)</script>';

    $this->actingAs($agent)->post('/tickets', newTicketPayload(['subject' => $payload, 'description' => $payload, 'customer_name' => $payload, 'customer_email' => '']))->assertRedirect();

    $ticket = ticketsOf($tenant)->sole();

    $this->actingAs($agent)->get(route('tickets.show', $ticket))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('selected.subject', $payload)
        ->where('selected.thread.0.body', $payload)
        ->where('selected.customer.name', $payload)
        ->where('tickets.0.subject', $payload));

    $html = $this->actingAs($agent)->get(route('tickets.show', $ticket))->getContent();

    // La página embebe los datos como JSON dentro de un atributo: las etiquetas viajan escapadas, nunca como HTML vivo.
    expect($html)->not->toContain('<script>alert(2)')->not->toContain('<img src=x');
});

it('limita la creación a 30 tickets por minuto por usuario', function (): void {
    $tenant = ticketTenant('negocio');
    $agent = ticketStaff($tenant);

    foreach (range(1, 30) as $i) {
        $this->actingAs($agent)->post('/tickets', newTicketPayload(['customer_name' => "Cliente {$i}", 'customer_email' => '', 'subject' => "Consulta {$i}"]))->assertRedirect();
    }

    // El plan Negocio permite 5000 al mes: el tope que frena aquí es el de frecuencia, no el del plan.
    $this->actingAs($agent)->post('/tickets', newTicketPayload(['customer_name' => 'Cliente 31', 'customer_email' => '']))->assertTooManyRequests();
});
