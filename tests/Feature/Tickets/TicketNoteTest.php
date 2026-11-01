<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketNote;
use App\Tenancy\TenantContext;
use App\Tickets\TicketStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * @return Collection<int, TicketNote>
 */
function notesOf(Ticket $ticket): Collection
{
    return app(TenantContext::class)->run($ticket->tenant()->firstOrFail(), fn () => TicketNote::query()->where('ticket_id', $ticket->id)->orderBy('id')->get());
}

afterEach(function (): void {
    Carbon::setTestNow();
});

it('guarda una nota interna a nombre de quien la escribe', function (): void {
    $tenant = ticketTenant();
    $agent = ticketStaff($tenant);
    $ticket = makeTicket($tenant);

    $this->actingAs($agent)->post("/tickets/{$ticket->id}/notes", ['body' => 'Revisar con facturación.'])->assertRedirect();

    expect(notesOf($ticket))->toHaveCount(1)
        ->and(notesOf($ticket)->first())->user_id->toBe($agent->id)->body->toBe('Revisar con facturación.');
});

it('una nota no es un mensaje: no entra en la tabla de mensajes ni mueve la bandeja', function (): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant, ['last_message_at' => Carbon::parse('2026-01-01 10:00:00')]);

    $this->actingAs(ticketStaff($tenant))->post("/tickets/{$ticket->id}/notes", ['body' => 'Solo para el equipo'])->assertRedirect();

    expect(app(TenantContext::class)->run($tenant, fn () => TicketMessage::query()->count()))->toBe(1)
        ->and(reloaded($ticket)->last_message_at->toDateTimeString())->toBe('2026-01-01 10:00:00');
});

it('el equipo ve las notas mezcladas con los mensajes por hora de creación', function (): void {
    $tenant = ticketTenant();
    $agent = ticketStaff($tenant);
    $ticket = makeTicket($tenant);

    Carbon::setTestNow(now()->addSecond());
    $this->actingAs($agent)->post("/tickets/{$ticket->id}/notes", ['body' => 'Primero la nota'])->assertRedirect();
    Carbon::setTestNow(now()->addMicroseconds(10));
    $this->actingAs($agent)->post("/tickets/{$ticket->id}/messages", ['body' => 'Después la respuesta'])->assertRedirect();
    Carbon::setTestNow(now()->addMicroseconds(10));
    $this->actingAs($agent)->post("/tickets/{$ticket->id}/notes", ['body' => 'Y otra nota'])->assertRedirect();

    $this->actingAs($agent)->get("/tickets/{$ticket->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->has('selected.thread', 4)
        ->where('selected.thread.0.kind', 'message')
        ->where('selected.thread.1.kind', 'note')->where('selected.thread.1.body', 'Primero la nota')->where('selected.thread.1.author_name', $agent->name)
        ->where('selected.thread.2.kind', 'message')->where('selected.thread.2.body', 'Después la respuesta')
        ->where('selected.thread.3.kind', 'note')->where('selected.thread.3.body', 'Y otra nota'));
});

it('un observador ve la conversación pero nunca las notas, ni en la respuesta ni en los datos compartidos', function (): void {
    $tenant = ticketTenant();
    $agent = ticketStaff($tenant);
    $ticket = makeTicket($tenant);
    makeNote($ticket, $agent, ['body' => 'SECRETO-INTERNO-123']);

    $response = $this->actingAs(ticketStaff($tenant, Role::Observer))->get("/tickets/{$ticket->id}");

    $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('selected.thread', 1)->where('selected.thread.0.kind', 'message'));
    expect($response->getContent())->not->toContain('SECRETO-INTERNO-123');
});

it('un agente y un administrador sí ven las notas', function (Role $role): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant);
    makeNote($ticket, ticketStaff($tenant), ['body' => 'Nota visible para el equipo']);

    $this->actingAs(ticketStaff($tenant, $role))->get("/tickets/{$ticket->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->has('selected.thread', 2)->where('selected.thread.1.body', 'Nota visible para el equipo'));
})->with([Role::Admin, Role::Agent]);

it('el texto de una nota se guarda y se entrega como texto plano', function (): void {
    $tenant = ticketTenant();
    $agent = ticketStaff($tenant);
    $ticket = makeTicket($tenant);

    $this->actingAs($agent)->post("/tickets/{$ticket->id}/notes", ['body' => '<script>alert(1)</script>'])->assertRedirect();

    $this->actingAs($agent)->get("/tickets/{$ticket->id}")->assertInertia(fn (AssertableInertia $page) => $page->where('selected.thread.1.body', '<script>alert(1)</script>'));
});

it('rechaza una nota vacía, enorme o con caracteres de control', function (mixed $body): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant);

    $this->actingAs(ticketStaff($tenant))->post("/tickets/{$ticket->id}/notes", ['body' => $body])->assertSessionHasErrors('body');

    expect(notesOf($ticket))->toHaveCount(0);
})->with([
    'vacía' => [''],
    'solo espacios' => ['    '],
    'más de 10 000' => [str_repeat('a', 10001)],
    'carácter nulo' => ["a\0b"],
]);

it('no se puede dejar una nota en un ticket cerrado', function (): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant, ['status' => TicketStatus::Closed]);

    $this->actingAs(ticketStaff($tenant))->post("/tickets/{$ticket->id}/notes", ['body' => 'Tarde'])->assertSessionHasErrors('body');

    expect(notesOf($ticket))->toHaveCount(0);
});

it('lo que el cliente intenta fijar a la fuerza se ignora', function (): void {
    $tenant = ticketTenant();
    $agent = ticketStaff($tenant);
    $ticket = makeTicket($tenant);
    $other = makeTicket(ticketTenant());

    $this->actingAs($agent)->post("/tickets/{$ticket->id}/notes", ['body' => 'Hola', 'user_id' => 999, 'tenant_id' => $other->tenant_id, 'ticket_id' => $other->id])->assertRedirect();

    expect(notesOf($ticket))->toHaveCount(1)->and(notesOf($ticket)->first())->user_id->toBe($agent->id)
        ->and(notesOf($other))->toHaveCount(0);
});

it('matriz de permisos para dejar notas', function (Role $role, int $status): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant);

    $this->actingAs(ticketStaff($tenant, $role))->post("/tickets/{$ticket->id}/notes", ['body' => 'Hola'])->assertStatus($status);
})->with([
    'administrador' => [Role::Admin, 302],
    'agente' => [Role::Agent, 302],
    'observador' => [Role::Observer, 403],
]);

it('otra empresa recibe 404 al dejar una nota y no ve las que existen', function (): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant);
    makeNote($ticket, ticketStaff($tenant), ['body' => 'SECRETO-INTERNO-456']);
    $intruder = ticketStaff(ticketTenant(), Role::Admin);

    $this->assertOtherTenantGets404($intruder, 'POST', "/tickets/{$ticket->id}/notes", ['body' => 'Hola']);
    $this->assertOtherTenantGets404($intruder, 'GET', "/tickets/{$ticket->id}");

    expect(notesOf($ticket))->toHaveCount(1);
});

it('sin empresa en contexto no se lee ninguna nota', function (): void {
    $tenant = ticketTenant();
    makeNote(makeTicket($tenant), ticketStaff($tenant));

    expect(TicketNote::query()->count())->toBe(0);
});

it('un visitante y el personal de la plataforma no pueden', function (): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant);

    $this->post("/tickets/{$ticket->id}/notes", ['body' => 'Hola'])->assertRedirect('/login');
    $this->actingAs(ticketStaff($tenant, Role::Agent, disabled: true))->post("/tickets/{$ticket->id}/notes", ['body' => 'Hola'])->assertRedirect(route('login'));
});

it('limita las notas a 60 por minuto y por persona, compartidas con las respuestas', function (): void {
    $tenant = ticketTenant();
    $agent = ticketStaff($tenant);
    $ticket = makeTicket($tenant);

    foreach (range(1, 30) as $i) {
        $this->actingAs($agent)->post("/tickets/{$ticket->id}/notes", ['body' => "Nota {$i}"])->assertRedirect();
        $this->actingAs($agent)->post("/tickets/{$ticket->id}/messages", ['body' => "Mensaje {$i}"])->assertRedirect();
    }

    $this->actingAs($agent)->post("/tickets/{$ticket->id}/notes", ['body' => 'Una de más'])->assertTooManyRequests();
});

it('solo hay consultas fijas al abrir un ticket con muchas notas y mensajes', function (): void {
    $tenant = ticketTenant();
    $agent = ticketStaff($tenant);
    $ticket = makeTicket($tenant);
    foreach (range(1, 15) as $i) {
        makeNote($ticket, ticketStaff($tenant), ['body' => "Nota {$i}"]);
    }

    $this->actingAs($agent)->get("/tickets/{$ticket->id}");
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->actingAs($agent)->get("/tickets/{$ticket->id}")->assertOk();
    $withFifteen = count(DB::getQueryLog());
    DB::flushQueryLog();

    foreach (range(1, 15) as $i) {
        makeNote($ticket, ticketStaff($tenant), ['body' => "Otra {$i}"]);
    }
    DB::flushQueryLog();
    $this->actingAs($agent)->get("/tickets/{$ticket->id}")->assertOk();
    $withThirty = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($withThirty)->toBe($withFifteen);
});
