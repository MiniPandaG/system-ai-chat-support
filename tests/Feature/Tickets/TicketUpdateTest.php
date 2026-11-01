<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Enums\AuditEvent;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Tickets\TicketPriority;
use App\Tickets\TicketStatus;

it('cambia el estado y deja las fechas de resuelto y cerrado coherentes', function (): void {
    $tenant = ticketTenant();
    $agent = ticketStaff($tenant);
    $ticket = makeTicket($tenant);

    $this->actingAs($agent)->patch("/tickets/{$ticket->id}", ['status' => 'waiting_customer'])->assertRedirect()->assertSessionHas('status', 'Ticket actualizado.');
    expect(reloaded($ticket))->status->toBe(TicketStatus::WaitingCustomer)->resolved_at->toBeNull()->closed_at->toBeNull();

    $this->actingAs($agent)->patch("/tickets/{$ticket->id}", ['status' => 'resolved'])->assertRedirect();
    expect(reloaded($ticket))->status->toBe(TicketStatus::Resolved)->resolved_at->not->toBeNull()->closed_at->toBeNull();

    $this->actingAs($agent)->patch("/tickets/{$ticket->id}", ['status' => 'closed'])->assertRedirect();
    expect(reloaded($ticket))->status->toBe(TicketStatus::Closed)->resolved_at->not->toBeNull()->closed_at->not->toBeNull();

    $this->actingAs($agent)->patch("/tickets/{$ticket->id}", ['status' => 'open'])->assertRedirect();
    expect(reloaded($ticket))->status->toBe(TicketStatus::Open)->resolved_at->toBeNull()->closed_at->toBeNull();
});

it('cerrar directamente un ticket abierto también lo marca como resuelto', function (): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant);

    $this->actingAs(ticketStaff($tenant))->patch("/tickets/{$ticket->id}", ['status' => 'closed'])->assertRedirect();

    expect(reloaded($ticket))->resolved_at->not->toBeNull()->closed_at->not->toBeNull();
});

it('cambia la prioridad sin tocar lo demás', function (): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant, ['priority' => TicketPriority::Low]);

    $this->actingAs(ticketStaff($tenant))->patch("/tickets/{$ticket->id}", ['priority' => 'urgent'])->assertRedirect();

    expect(reloaded($ticket))->priority->toBe(TicketPriority::Urgent)->status->toBe(TicketStatus::Open);
});

it('asigna, reasigna y deja sin asignar, y audita solo cuando cambia quien atiende', function (): void {
    $tenant = ticketTenant();
    $agent = ticketStaff($tenant);
    $colleague = ticketStaff($tenant, Role::Admin);
    $ticket = makeTicket($tenant);

    $this->actingAs($agent)->patch("/tickets/{$ticket->id}", ['assigned_to' => $agent->id])->assertRedirect();
    $this->actingAs($agent)->patch("/tickets/{$ticket->id}", ['assigned_to' => $agent->id])->assertRedirect();
    $this->actingAs($agent)->patch("/tickets/{$ticket->id}", ['assigned_to' => $colleague->id])->assertRedirect();
    $this->actingAs($agent)->patch("/tickets/{$ticket->id}", ['priority' => 'high'])->assertRedirect();
    $this->actingAs($agent)->patch("/tickets/{$ticket->id}", ['assigned_to' => null])->assertRedirect();

    expect(reloaded($ticket)->assigned_to)->toBeNull();

    $events = AuditLog::query()->where('event', AuditEvent::TicketAssigned->value)->orderBy('id')->get()->map->context->all();

    expect($events)->toEqual([
        ['ticket_id' => $ticket->id, 'from' => null, 'to' => $agent->id],
        ['ticket_id' => $ticket->id, 'from' => $agent->id, 'to' => $colleague->id],
        ['ticket_id' => $ticket->id, 'from' => $colleague->id, 'to' => null],
    ]);
});

it('no asigna a quien no atiende ni a nadie de otra empresa', function (string $case): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant);
    $target = match ($case) {
        'observador' => ticketStaff($tenant, Role::Observer)->id,
        'desactivado' => ticketStaff($tenant, Role::Agent, disabled: true)->id,
        'otra empresa' => ticketStaff(ticketTenant())->id,
        default => 999_999,
    };

    $this->actingAs(ticketStaff($tenant))->patch("/tickets/{$ticket->id}", ['assigned_to' => $target])
        ->assertSessionHasErrors(['assigned_to' => 'Esa persona no puede atender tickets: elige a un administrador o agente activo.']);

    expect(reloaded($ticket)->assigned_to)->toBeNull();
})->with(['observador', 'desactivado', 'otra empresa', 'inexistente']);

it('rechaza estados y prioridades que no existen', function (array $payload, string $field): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant);

    $this->actingAs(ticketStaff($tenant))->patch("/tickets/{$ticket->id}", $payload)->assertSessionHasErrors($field);

    expect(reloaded($ticket))->status->toBe(TicketStatus::Open)->priority->toBe(TicketPriority::Normal);
})->with([
    'estado desconocido' => [['status' => 'borrado'], 'status'],
    'estado vacío' => [['status' => ''], 'status'],
    'estado como lista' => [['status' => ['open']], 'status'],
    'prioridad desconocida' => [['priority' => 'critica'], 'priority'],
    'prioridad vacía' => [['priority' => ''], 'priority'],
]);

it('ignora lo que no se puede cambiar desde aquí', function (): void {
    $tenant = ticketTenant();
    $other = ticketTenant();
    $ticket = makeTicket($tenant);

    $this->actingAs(ticketStaff($tenant))->patch("/tickets/{$ticket->id}", [
        'priority' => 'high',
        'tenant_id' => $other->id,
        'number' => 999,
        'subject' => 'Asunto cambiado',
        'customer_id' => 1,
        'created_by' => 1,
    ])->assertRedirect();

    $fresh = reloaded($ticket);

    expect($fresh->tenant_id)->toBe($tenant->id)
        ->and($fresh->number)->toBe($ticket->number)
        ->and($fresh->subject)->toBe($ticket->subject)
        ->and($fresh->customer_id)->toBe($ticket->customer_id)
        ->and($fresh->priority)->toBe(TicketPriority::High);
});

it('una petición vacía no cambia nada', function (): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant, ['assigned_to' => ticketStaff($tenant)->id]);

    $this->actingAs(ticketStaff($tenant))->patch("/tickets/{$ticket->id}", [])->assertRedirect();

    expect(reloaded($ticket)->assigned_to)->toBe($ticket->assigned_to);
});

it('mantiene el ticket asignado a alguien que luego se desactivó hasta que se reasigne', function (): void {
    $tenant = ticketTenant();
    $former = ticketStaff($tenant);
    $ticket = makeTicket($tenant, ['assigned_to' => $former->id]);
    $former->forceFill(['disabled_at' => now()])->save();

    $this->actingAs(ticketStaff($tenant))->patch("/tickets/{$ticket->id}", ['priority' => 'high'])->assertRedirect();

    expect(reloaded($ticket)->assigned_to)->toBe($former->id);
});

it('limita los cambios a 120 por minuto por usuario', function (): void {
    $tenant = ticketTenant();
    $agent = ticketStaff($tenant);
    $ticket = makeTicket($tenant);

    foreach (range(1, 120) as $i) {
        $this->actingAs($agent)->patch("/tickets/{$ticket->id}", ['priority' => $i % 2 === 0 ? 'high' : 'low'])->assertRedirect();
    }

    $this->actingAs($agent)->patch("/tickets/{$ticket->id}", ['priority' => 'urgent'])->assertTooManyRequests();
});
