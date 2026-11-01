<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tickets\MessageAuthor;
use App\Tickets\TicketStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @return Collection<int, TicketMessage>
 */
function messagesOf(Ticket $ticket): Collection
{
    return app(TenantContext::class)->run($ticket->tenant()->firstOrFail(), fn () => TicketMessage::query()->where('ticket_id', $ticket->id)->orderBy('id')->get());
}

afterEach(function (): void {
    Carbon::setTestNow();
});

it('responde al cliente con un mensaje del agente y sube el ticket en la bandeja', function (): void {
    $tenant = ticketTenant();
    $agent = ticketStaff($tenant);
    $ticket = makeTicket($tenant, ['last_message_at' => now()->subDay()]);

    $this->actingAs($agent)->post("/tickets/{$ticket->id}/messages", ['body' => 'Ya restablecimos tu contraseña.'])->assertRedirect();

    $messages = messagesOf($ticket);
    expect($messages)->toHaveCount(2)
        ->and($messages->last())->author->toBe(MessageAuthor::Agent)->user_id->toBe($agent->id)->body->toBe('Ya restablecimos tu contraseña.')
        ->and(reloaded($ticket)->last_message_at->isToday())->toBeTrue();
});

it('responder no cambia el estado ni quien atiende', function (): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant, ['status' => TicketStatus::WaitingCustomer]);

    $this->actingAs(ticketStaff($tenant))->post("/tickets/{$ticket->id}/messages", ['body' => 'Hola'])->assertRedirect();

    expect(reloaded($ticket))->status->toBe(TicketStatus::WaitingCustomer)->assigned_to->toBeNull();
});

it('el texto se guarda tal cual: sin HTML interpretado, con saltos de línea y tabulaciones', function (): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant);
    $body = "<script>alert(1)</script> <b>hola</b>\n\tsegunda línea & más";

    $this->actingAs(ticketStaff($tenant))->post("/tickets/{$ticket->id}/messages", ['body' => $body])->assertRedirect();

    expect(messagesOf($ticket)->last())->body->toBe($body);
});

it('el texto llega a la pantalla como dato, nunca como HTML', function (): void {
    $tenant = ticketTenant();
    $agent = ticketStaff($tenant);
    $ticket = makeTicket($tenant);

    $this->actingAs($agent)->post("/tickets/{$ticket->id}/messages", ['body' => '<img src=x onerror=alert(1)>'])->assertRedirect();

    $this->actingAs($agent)->get("/tickets/{$ticket->id}")->assertInertia(fn ($page) => $page
        ->where('selected.thread.1.kind', 'message')
        ->where('selected.thread.1.body', '<img src=x onerror=alert(1)>'));
});

it('rechaza un mensaje vacío, solo espacios, demasiado largo o con caracteres de control', function (mixed $body): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant);

    $this->actingAs(ticketStaff($tenant))->post("/tickets/{$ticket->id}/messages", ['body' => $body])->assertSessionHasErrors('body');

    expect(messagesOf($ticket))->toHaveCount(1);
})->with([
    'vacío' => [''],
    'solo espacios' => ["   \n  "],
    'sin campo' => [null],
    'más de 10 000' => [str_repeat('a', 10001)],
    'carácter nulo' => ["hola\0mundo"],
    'escape' => ["hola\x1Bmundo"],
    'una lista' => [['a']],
]);

it('acepta exactamente 10 000 caracteres', function (): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant);

    $this->actingAs(ticketStaff($tenant))->post("/tickets/{$ticket->id}/messages", ['body' => str_repeat('a', 10000)])->assertRedirect()->assertSessionHasNoErrors();
});

it('no se puede escribir en un ticket cerrado', function (): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant, ['status' => TicketStatus::Closed]);

    $response = $this->actingAs(ticketStaff($tenant))->post("/tickets/{$ticket->id}/messages", ['body' => 'Hola']);

    $response->assertSessionHasErrors('body');
    expect(session('errors')?->first('body'))->toBe('Este ticket está cerrado. Reábrelo para escribir en él.')
        ->and(messagesOf($ticket))->toHaveCount(1);
});

it('un ticket resuelto sí admite respuesta', function (): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant, ['status' => TicketStatus::Resolved]);

    $this->actingAs(ticketStaff($tenant))->post("/tickets/{$ticket->id}/messages", ['body' => 'Una cosa más'])->assertRedirect()->assertSessionHasNoErrors();
});

it('lo que el cliente intenta fijar a la fuerza se ignora', function (): void {
    $tenant = ticketTenant();
    $agent = ticketStaff($tenant);
    $ticket = makeTicket($tenant);
    $other = makeTicket(ticketTenant());

    $this->actingAs($agent)->post("/tickets/{$ticket->id}/messages", [
        'body' => 'Hola',
        'author' => 'customer',
        'user_id' => 999,
        'tenant_id' => $other->tenant_id,
        'ticket_id' => $other->id,
    ])->assertRedirect();

    expect(messagesOf($ticket)->last())->author->toBe(MessageAuthor::Agent)->user_id->toBe($agent->id)->ticket_id->toBe($ticket->id)
        ->and(messagesOf($other))->toHaveCount(1);
});

it('matriz de permisos para responder', function (Role $role, int $status): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant);

    $this->actingAs(ticketStaff($tenant, $role))->post("/tickets/{$ticket->id}/messages", ['body' => 'Hola'])->assertStatus($status);
})->with([
    'administrador' => [Role::Admin, 302],
    'agente' => [Role::Agent, 302],
    'observador' => [Role::Observer, 403],
]);

it('un observador no deja ningún mensaje', function (): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant);

    $this->actingAs(ticketStaff($tenant, Role::Observer))->post("/tickets/{$ticket->id}/messages", ['body' => 'Hola'])->assertForbidden();

    expect(messagesOf($ticket))->toHaveCount(1);
});

it('quien no ha iniciado sesión, el desactivado y el personal de la plataforma no responden', function (): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant);
    $staff = User::factory()->create(['is_platform_admin' => true]);
    $staff->forceFill(['two_factor_secret' => encrypt('S'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()])->save();

    $this->post("/tickets/{$ticket->id}/messages", ['body' => 'Hola'])->assertRedirect('/login');
    $this->actingAs(ticketStaff($tenant, Role::Agent, disabled: true))->post("/tickets/{$ticket->id}/messages", ['body' => 'Hola'])->assertRedirect(route('login'));
    $this->actingAs($staff)->post("/tickets/{$ticket->id}/messages", ['body' => 'Hola'])->assertNotFound();

    expect(messagesOf($ticket))->toHaveCount(1);
});

it('otra empresa recibe 404 al responder y no se crea nada', function (): void {
    $ticket = makeTicket(ticketTenant());
    $intruder = ticketStaff(ticketTenant(), Role::Admin);

    $this->assertOtherTenantGets404($intruder, 'POST', "/tickets/{$ticket->id}/messages", ['body' => 'Hola']);

    expect(messagesOf($ticket))->toHaveCount(1);
});

it('el ticket debe ser un número', function (): void {
    $this->actingAs(ticketStaff(ticketTenant()))->post('/tickets/abc/messages', ['body' => 'Hola'])->assertNotFound();
});

it('limita las respuestas a 60 por minuto y por persona', function (): void {
    $tenant = ticketTenant();
    $agent = ticketStaff($tenant);
    $ticket = makeTicket($tenant);

    foreach (range(1, 60) as $i) {
        $this->actingAs($agent)->post("/tickets/{$ticket->id}/messages", ['body' => "Mensaje {$i}"])->assertRedirect();
    }

    $this->actingAs($agent)->post("/tickets/{$ticket->id}/messages", ['body' => 'Uno de más'])->assertTooManyRequests();
    expect(messagesOf($ticket))->toHaveCount(61);
});
