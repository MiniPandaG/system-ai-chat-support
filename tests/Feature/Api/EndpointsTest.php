<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Enums\Role;
use App\Events\TicketChanged;
use App\Events\WidgetChanged;
use App\Mail\TicketEmail;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tickets\Channel;
use App\Tickets\MessageAuthor;
use App\Tickets\TicketPriority;
use App\Tickets\TicketStatus;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

function apiTicketsOf(Tenant $tenant): array
{
    return app(TenantContext::class)->run($tenant, fn () => Ticket::query()->orderBy('id')->get()->all());
}

describe('crear tickets', function (): void {
    it('crea el ticket del canal api con su primer mensaje, número, cliente y auditoría sin persona', function (): void {
        $tenant = widgetTenant();
        $key = apiKeyFor($tenant);

        $response = api($this, 'POST', '/tickets', $key, newApiTicket())->assertCreated();

        $data = $response->json('data');
        $ticket = apiTicketsOf($tenant)[0];
        expect(array_keys($data))->toBe(['id', 'number', 'subject', 'status', 'priority', 'channel', 'customer_id', 'assigned_to', 'created_at', 'last_message_at', 'resolved_at', 'closed_at'])
            ->and($data['number'])->toBe(1)->and($data['status'])->toBe('open')->and($data['priority'])->toBe('normal')->and($data['channel'])->toBe('api')
            ->and($response->headers->get('Location'))->toBe(url('/api/v1/tickets/'.$ticket->id))
            ->and($ticket->channel)->toBe(Channel::Api)->and($ticket->created_by)->toBeNull();
        expect(widgetBodies($tenant, $ticket))->toBe(['Hice el pedido hace una semana y no ha llegado.']);
        $log = AuditLog::query()->where('event', 'ticket.created')->sole();
        expect($log->user_id)->toBeNull()->and($log->context)->toEqual(['ticket_id' => $ticket->id, 'number' => 1, 'via' => 'api']);
    });

    it('un correo que ya es de un cliente de la empresa reutiliza a ese cliente; uno nuevo lo crea', function (): void {
        $tenant = widgetTenant();
        $key = apiKeyFor($tenant);

        api($this, 'POST', '/tickets', $key, newApiTicket())->assertCreated();
        api($this, 'POST', '/tickets', $key, newApiTicket(['subject' => 'Otro asunto', 'customer_email' => 'MARISOL@cliente-ejemplo.com']))->assertCreated();
        api($this, 'POST', '/tickets', $key, newApiTicket(['customer_email' => 'otra@cliente.com', 'customer_name' => 'Otra Persona']))->assertCreated();

        $tickets = apiTicketsOf($tenant);
        expect($tickets[0]->customer_id)->toBe($tickets[1]->customer_id)->and($tickets[2]->customer_id)->not->toBe($tickets[0]->customer_id);
        expect(app(TenantContext::class)->run($tenant, fn () => Customer::query()->count()))->toBe(2);
    });

    it('acepta un cliente existente por su identificador, la prioridad y quien atiende', function (): void {
        $tenant = widgetTenant();
        $key = apiKeyFor($tenant);
        $agent = ticketStaff($tenant, Role::Agent);
        $customer = app(TenantContext::class)->run($tenant, fn () => Customer::factory()->create());

        $response = api($this, 'POST', '/tickets', $key, ['subject' => 'Con todo', 'description' => 'Texto', 'customer_id' => $customer->id, 'priority' => 'urgent', 'assigned_to' => $agent->id])->assertCreated();

        expect($response->json('data.customer_id'))->toBe($customer->id)->and($response->json('data.priority'))->toBe('urgent')->and($response->json('data.assigned_to'))->toBe($agent->id);
    });

    it('rechaza datos mal formados o que apuntan a otra empresa, con los detalles por campo', function (array $overrides, string $field): void {
        $tenant = widgetTenant();
        $other = widgetTenant();
        $key = apiKeyFor($tenant);
        $overrides = array_map(fn (mixed $value): mixed => $value === '@OTRO_CLIENTE' ? app(TenantContext::class)->run($other, fn () => Customer::factory()->create())->id : ($value === '@OTRO_AGENTE' ? ticketStaff($other, Role::Agent)->id : ($value === '@OBSERVADOR' ? ticketStaff($tenant, Role::Observer)->id : $value)), $overrides);

        $response = api($this, 'POST', '/tickets', $key, newApiTicket($overrides))->assertStatus(422);

        expect($response->json('error.code'))->toBe('validation_failed')->and(array_keys($response->json('error.details')))->toContain($field);
        expect(apiTicketsOf($tenant))->toBe([]);
    })->with([
        'sin asunto' => [['subject' => ''], 'subject'],
        'asunto de varias líneas' => [['subject' => "uno\ndos"], 'subject'],
        'asunto largo' => [['subject' => str_repeat('a', 151)], 'subject'],
        'sin descripción' => [['description' => ''], 'description'],
        'descripción con control' => [['description' => "a\x00b"], 'description'],
        'descripción larga' => [['description' => str_repeat('a', 10001)], 'description'],
        'prioridad inventada' => [['priority' => 'inmediata'], 'priority'],
        'correo mal escrito' => [['customer_email' => 'no-es-correo'], 'customer_email'],
        'sin cliente' => [['customer_name' => null, 'customer_email' => null], 'customer_name'],
        'cliente de otra empresa' => [['customer_id' => '@OTRO_CLIENTE'], 'customer_id'],
        'agente de otra empresa' => [['assigned_to' => '@OTRO_AGENTE'], 'assigned_to'],
        'observador como agente' => [['assigned_to' => '@OBSERVADOR'], 'assigned_to'],
        'agente inexistente' => [['assigned_to' => 99999999], 'assigned_to'],
    ]);

    it('ignora lo que el cliente intente fijar: empresa, estado, canal, número, creador', function (): void {
        $tenant = widgetTenant();
        $other = widgetTenant();
        $key = apiKeyFor($tenant);

        api($this, 'POST', '/tickets', $key, newApiTicket(['tenant_id' => $other->id, 'status' => 'closed', 'channel' => 'panel', 'number' => 500, 'created_by' => 1]))->assertCreated();

        $ticket = apiTicketsOf($tenant)[0];
        expect($ticket->tenant_id)->toBe($tenant->id)->and($ticket->status)->toBe(TicketStatus::Open)->and($ticket->channel)->toBe(Channel::Api)
            ->and($ticket->number)->toBe(1)->and($ticket->created_by)->toBeNull()->and(apiTicketsOf($other))->toBe([]);
    });

    it('el texto se guarda tal cual, sin interpretarlo', function (): void {
        $tenant = widgetTenant();
        $key = apiKeyFor($tenant);

        api($this, 'POST', '/tickets', $key, newApiTicket(['subject' => '<script>x</script>', 'description' => '<img src=x onerror=1> **b**']))->assertCreated();

        $ticket = apiTicketsOf($tenant)[0];
        expect($ticket->subject)->toBe('<script>x</script>')->and(widgetBodies($tenant, $ticket))->toBe(['<img src=x onerror=1> **b**']);
    });

    it('aparece en la bandeja del equipo con su canal', function (): void {
        $tenant = widgetTenant();
        api($this, 'POST', '/tickets', apiKeyFor($tenant), newApiTicket())->assertCreated();
        $ticket = apiTicketsOf($tenant)[0];

        $detail = $this->actingAs(ticketStaff($tenant))->get("/tickets/{$ticket->id}")->assertOk();

        expect(inboxProp($detail, 'selected.channel'))->toBe('api');
    });
});

describe('consultar tickets', function (): void {
    it('devuelve uno por su identificador y nunca uno de otra empresa', function (): void {
        $tenant = widgetTenant();
        $ticket = makeTicket($tenant, ['subject' => 'Visible']);

        $data = api($this, 'GET', "/tickets/{$ticket->id}", apiKeyFor($tenant))->assertOk()->json('data');

        expect($data['subject'])->toBe('Visible')->and($data['id'])->toBe($ticket->id);
    });

    it('lista de la más reciente a la más antigua, con cursor, sin repetir ni saltar ninguno', function (): void {
        $tenant = widgetTenant();
        $key = apiKeyFor($tenant);
        foreach (range(1, 7) as $n) {
            makeTicket($tenant, ['subject' => "Ticket {$n}"]);
        }
        $seen = [];
        $cursor = null;

        do {
            $page = api($this, 'GET', '/tickets', $key, array_filter(['limit' => 3, 'cursor' => $cursor]))->assertOk();
            array_push($seen, ...array_column($page->json('data'), 'subject'));
            $cursor = $page->json('next_cursor');
        } while ($cursor !== null);

        expect($seen)->toBe(['Ticket 7', 'Ticket 6', 'Ticket 5', 'Ticket 4', 'Ticket 3', 'Ticket 2', 'Ticket 1']);
    });

    it('la última página trae next_cursor nulo y una lista vacía es válida', function (): void {
        $tenant = widgetTenant();
        $key = apiKeyFor($tenant);

        $empty = api($this, 'GET', '/tickets', $key)->assertOk();
        expect($empty->json())->toBe(['data' => [], 'next_cursor' => null]);

        makeTicket($tenant);
        expect(api($this, 'GET', '/tickets', $key, ['limit' => 1])->json('next_cursor'))->toBeNull();
    });

    it('filtra por estado, prioridad, quien atiende, cliente y número', function (): void {
        $tenant = widgetTenant();
        $key = apiKeyFor($tenant);
        $agent = ticketStaff($tenant, Role::Agent);
        $customer = app(TenantContext::class)->run($tenant, fn () => Customer::factory()->create());
        makeTicket($tenant, ['subject' => 'A', 'status' => TicketStatus::Resolved]);
        makeTicket($tenant, ['subject' => 'B', 'priority' => TicketPriority::Urgent, 'assigned_to' => $agent->id]);
        $c = makeTicket($tenant, ['subject' => 'C'], $customer);
        $subjects = fn (array $query): array => array_column(api($this, 'GET', '/tickets', $key, $query)->assertOk()->json('data'), 'subject');

        expect($subjects(['status' => 'resolved']))->toBe(['A'])
            ->and($subjects(['priority' => 'urgent']))->toBe(['B'])
            ->and($subjects(['assigned_to' => $agent->id]))->toBe(['B'])
            ->and($subjects(['assigned_to' => 'none']))->toBe(['C', 'A'])
            ->and($subjects(['customer_id' => $customer->id]))->toBe(['C'])
            ->and($subjects(['number' => $c->number]))->toBe(['C']);
    });

    it('solo lista los de su empresa', function (): void {
        $mine = widgetTenant();
        $theirs = widgetTenant();
        makeTicket($mine, ['subject' => 'Mío']);
        makeTicket($theirs, ['subject' => 'Ajeno']);

        $subjects = array_column(api($this, 'GET', '/tickets', apiKeyFor($mine))->json('data'), 'subject');

        expect($subjects)->toBe(['Mío']);
    });

    it('rechaza parámetros inválidos con 422 y no los usa', function (array $query): void {
        $tenant = widgetTenant();

        api($this, 'GET', '/tickets', apiKeyFor($tenant), $query)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    })->with([
        'limit cero' => [['limit' => 0]],
        'limit enorme' => [['limit' => 101]],
        'limit texto' => [['limit' => 'todos']],
        'cursor negativo' => [['cursor' => -5]],
        'cursor texto' => [['cursor' => "1' OR '1'='1"]],
        'estado inventado' => [['status' => 'borrado']],
        'prioridad inventada' => [['priority' => 'x']],
        'assigned_to inyección' => [['assigned_to' => '1 OR 1=1']],
        'arreglo' => [['status' => ['open']]],
    ]);
});

describe('cambiar un ticket', function (): void {
    it('cambia estado, prioridad y quien atiende, con las fechas de resuelto y cerrado, y lo audita sin persona', function (): void {
        $tenant = widgetTenant();
        $key = apiKeyFor($tenant);
        $ticket = makeTicket($tenant);
        $agent = ticketStaff($tenant, Role::Agent);

        $data = api($this, 'PATCH', "/tickets/{$ticket->id}", $key, ['status' => 'resolved', 'priority' => 'high', 'assigned_to' => $agent->id])->assertOk()->json('data');

        expect($data['status'])->toBe('resolved')->and($data['priority'])->toBe('high')->and($data['assigned_to'])->toBe($agent->id)->and($data['resolved_at'])->not->toBeNull();
        $log = AuditLog::query()->where('event', 'ticket.assigned')->sole();
        expect($log->user_id)->toBeNull();
        expect(api($this, 'PATCH', "/tickets/{$ticket->id}", $key, ['status' => 'closed', 'assigned_to' => null])->json('data.closed_at'))->not->toBeNull();
        expect(reloaded($ticket)->assigned_to)->toBeNull();
    });

    it('un cuerpo vacío no cambia nada y valida lo que llega', function (): void {
        $tenant = widgetTenant();
        $key = apiKeyFor($tenant);
        $ticket = makeTicket($tenant);

        api($this, 'PATCH', "/tickets/{$ticket->id}", $key, [])->assertOk();
        api($this, 'PATCH', "/tickets/{$ticket->id}", $key, ['status' => 'borrado'])->assertStatus(422);
        api($this, 'PATCH', "/tickets/{$ticket->id}", $key, ['assigned_to' => ticketStaff(widgetTenant(), Role::Agent)->id])->assertStatus(422);
        api($this, 'PATCH', "/tickets/{$ticket->id}", $key, ['subject' => 'Nuevo asunto', 'customer_id' => 5])->assertOk();

        $fresh = reloaded($ticket);
        expect($fresh->status)->toBe(TicketStatus::Open)->and($fresh->subject)->toBe($ticket->subject)->and($fresh->customer_id)->toBe($ticket->customer_id);
    });

    it('avisa en vivo al equipo y al visitante del widget cuando cambia el estado', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant);
        $ticket = widgetTicketOf($tenant, $token);
        Queue::fake();

        api($this, 'PATCH', "/tickets/{$ticket->id}", apiKeyFor($tenant), ['status' => 'resolved'])->assertOk();

        $classes = Queue::pushed(BroadcastEvent::class)->map(fn (BroadcastEvent $job): string => $job->event::class)->all();
        expect($classes)->toContain(TicketChanged::class)->toContain(WidgetChanged::class);
    });
});

describe('mensajes', function (): void {
    it('lista solo mensajes, en orden, con cursor, y nunca las notas internas', function (): void {
        $tenant = widgetTenant();
        $key = apiKeyFor($tenant);
        $ticket = makeTicket($tenant);
        makeNote($ticket, ticketStaff($tenant), ['body' => 'NOTA-SECRETA']);
        foreach (['dos', 'tres', 'cuatro'] as $body) {
            api($this, 'POST', "/tickets/{$ticket->id}/messages", $key, ['body' => $body])->assertCreated();
        }

        $first = api($this, 'GET', "/tickets/{$ticket->id}/messages", $key, ['limit' => 2])->assertOk();
        $second = api($this, 'GET', "/tickets/{$ticket->id}/messages", $key, ['limit' => 2, 'cursor' => $first->json('next_cursor')])->assertOk();

        $bodies = [...array_column($first->json('data'), 'body'), ...array_column($second->json('data'), 'body')];
        expect($bodies)->toBe(['Mensaje de ejemplo del cliente.', 'dos', 'tres', 'cuatro'])->and($second->json('next_cursor'))->toBeNull()
            ->and($first->getContent().$second->getContent())->not->toContain('NOTA-SECRETA');
        expect(array_keys($first->json('data.0')))->toBe(['id', 'ticket_id', 'author', 'body', 'created_at']);
    });

    it('un mensaje del cliente reabre un ticket resuelto y mueve la bandeja', function (): void {
        $tenant = widgetTenant();
        $ticket = makeTicket($tenant, ['status' => TicketStatus::Resolved, 'resolved_at' => now()]);
        $this->travel(5)->minutes();

        $data = api($this, 'POST', "/tickets/{$ticket->id}/messages", apiKeyFor($tenant), ['body' => 'Sigo con el problema'])->assertCreated()->json('data');

        expect($data['author'])->toBe('customer')->and(reloaded($ticket)->status)->toBe(TicketStatus::Open)->and(reloaded($ticket)->resolved_at)->toBeNull()
            ->and(reloaded($ticket)->last_message_at->isAfter($ticket->last_message_at))->toBeTrue();
    });

    it('un ticket cerrado no recibe mensajes', function (): void {
        $tenant = widgetTenant();
        $ticket = makeTicket($tenant, ['status' => TicketStatus::Closed]);

        $response = api($this, 'POST', "/tickets/{$ticket->id}/messages", apiKeyFor($tenant), ['body' => 'Hola'])->assertStatus(422);

        expect($response->json('error.details.body.0'))->toBe('Este ticket está cerrado. Reábrelo para escribir en él.')->and(widgetBodies($tenant, $ticket))->toHaveCount(1);
    });

    it('un mensaje del equipo no lleva persona, se muestra como «Integración» y avisa por correo si el cliente dejó el suyo', function (): void {
        Mail::fake();
        $tenant = widgetTenant();
        $customer = app(TenantContext::class)->run($tenant, fn () => Customer::factory()->create(['email' => 'marisol@cliente-ejemplo.com']));
        $ticket = makeTicket($tenant, [], $customer);

        $data = api($this, 'POST', "/tickets/{$ticket->id}/messages", apiKeyFor($tenant), ['body' => 'Respuesta del sistema', 'author' => 'agent'])->assertCreated()->json('data');

        $message = app(TenantContext::class)->run($tenant, fn () => TicketMessage::query()->findOrFail($data['id']));
        expect($message->author)->toBe(MessageAuthor::Agent)->and($message->user_id)->toBeNull();
        Mail::assertSent(TicketEmail::class, fn (TicketEmail $mail): bool => $mail->hasTo('marisol@cliente-ejemplo.com'));
        $thread = $this->actingAs(ticketStaff($tenant))->get("/tickets/{$ticket->id}")->assertOk()->viewData('page')['props']['selected']['thread'];
        expect(collect($thread)->firstWhere('id', $data['id'])['author_name'])->toBe('Integración');
    });

    it('valida el texto y el autor', function (array $payload, string $field): void {
        $tenant = widgetTenant();
        $ticket = makeTicket($tenant);

        $response = api($this, 'POST', "/tickets/{$ticket->id}/messages", apiKeyFor($tenant), $payload)->assertStatus(422);

        expect(array_keys($response->json('error.details')))->toContain($field);
    })->with([
        'vacío' => [['body' => ''], 'body'],
        'solo espacios' => [['body' => "  \n "], 'body'],
        'largo' => [['body' => str_repeat('a', 10001)], 'body'],
        'control' => [['body' => "a\x07"], 'body'],
        'autor inventado' => [['body' => 'x', 'author' => 'administrador'], 'author'],
    ]);

    it('lo que intente fijar el cliente se ignora: usuario, ticket y fecha', function (): void {
        $tenant = widgetTenant();
        $ticket = makeTicket($tenant);
        $other = makeTicket($tenant);

        $data = api($this, 'POST', "/tickets/{$ticket->id}/messages", apiKeyFor($tenant), ['body' => 'Hola', 'user_id' => 1, 'ticket_id' => $other->id, 'created_at' => '2001-01-01'])->assertCreated()->json('data');

        expect($data['ticket_id'])->toBe($ticket->id)->and(widgetBodies($tenant, $other))->toHaveCount(1);
    });
});

describe('clientes', function (): void {
    it('crea, consulta y busca clientes de su empresa', function (): void {
        $tenant = widgetTenant();
        $key = apiKeyFor($tenant);

        $created = api($this, 'POST', '/customers', $key, ['name' => '  Marisol Quintero ', 'email' => 'Marisol@Cliente.com'])->assertCreated();
        $id = $created->json('data.id');
        api($this, 'POST', '/customers', $key, ['name' => 'Otro Cliente', 'email' => 'otro@cliente.com'])->assertCreated();

        expect($created->json('data'))->toMatchArray(['name' => 'Marisol Quintero', 'email' => 'marisol@cliente.com'])
            ->and(array_keys($created->json('data')))->toBe(['id', 'name', 'email', 'created_at'])
            ->and(api($this, 'GET', "/customers/{$id}", $key)->json('data.email'))->toBe('marisol@cliente.com')
            ->and(array_column(api($this, 'GET', '/customers', $key, ['email' => 'MARISOL@cliente.com'])->json('data'), 'name'))->toBe(['Marisol Quintero'])
            ->and(array_column(api($this, 'GET', '/customers', $key, ['q' => 'otro'])->json('data'), 'name'))->toBe(['Otro Cliente'])
            ->and(api($this, 'GET', '/customers', $key, ['q' => 'o'])->status())->toBe(422);
    });

    it('un correo repetido en la empresa se rechaza; en otra empresa es otro cliente', function (): void {
        $mine = widgetTenant();
        $theirs = widgetTenant();
        api($this, 'POST', '/customers', apiKeyFor($mine), ['name' => 'Uno', 'email' => 'x@cliente.com'])->assertCreated();

        $repeated = api($this, 'POST', '/customers', apiKeyFor($mine), ['name' => 'Dos', 'email' => 'X@cliente.com'])->assertStatus(422);

        expect(array_keys($repeated->json('error.details')))->toBe(['email']);
        api($this, 'POST', '/customers', apiKeyFor($theirs), ['name' => 'Uno', 'email' => 'x@cliente.com'])->assertCreated();
    });

    it('la búsqueda trata % y _ como texto, no como comodines', function (): void {
        $tenant = widgetTenant();
        $key = apiKeyFor($tenant);
        api($this, 'POST', '/customers', $key, ['name' => 'Ana López'])->assertCreated();
        api($this, 'POST', '/customers', $key, ['name' => '100% Real'])->assertCreated();

        expect(array_column(api($this, 'GET', '/customers', $key, ['q' => '%%'])->json('data'), 'name'))->toBe([])
            ->and(array_column(api($this, 'GET', '/customers', $key, ['q' => '0% R'])->json('data'), 'name'))->toBe(['100% Real']);
    });

    it('un cliente de otra empresa da 404 y no aparece en las listas', function (): void {
        $mine = widgetTenant();
        $theirs = widgetTenant();
        $other = app(TenantContext::class)->run($theirs, fn () => Customer::factory()->create(['name' => 'Ajeno Absoluto']));

        api($this, 'GET', "/customers/{$other->id}", apiKeyFor($mine))->assertNotFound();
        expect(api($this, 'GET', '/customers', apiKeyFor($mine))->getContent())->not->toContain('Ajeno Absoluto');
    });

    it('valida el nombre y el correo', function (array $payload, string $field): void {
        $tenant = widgetTenant();

        $response = api($this, 'POST', '/customers', apiKeyFor($tenant), $payload)->assertStatus(422);

        expect(array_keys($response->json('error.details')))->toContain($field);
    })->with([
        'sin nombre' => [['email' => 'a@b.com'], 'name'],
        'nombre vacío' => [['name' => '  '], 'name'],
        'nombre largo' => [['name' => str_repeat('a', 121)], 'name'],
        'nombre con control' => [['name' => "a\x07"], 'name'],
        'correo mal escrito' => [['name' => 'A', 'email' => 'mal'], 'email'],
    ]);
});

describe('agentes', function (): void {
    it('lista solo administradores y agentes activos de su empresa, con nombre y rol', function (): void {
        $tenant = widgetTenant();
        $other = widgetTenant();
        $admin = widgetAdmin($tenant);
        $agent = ticketStaff($tenant, Role::Agent);
        ticketStaff($tenant, Role::Observer);
        ticketStaff($tenant, Role::Agent, disabled: true);
        ticketStaff($other, Role::Agent);

        $key = apiKeyFor($tenant);
        $data = api($this, 'GET', '/agents', $key)->assertOk()->json('data');

        // El administrador que crea la llave también cuenta; el observador, el desactivado y los de otra empresa, no.
        $expected = User::query()->inTenant($tenant->id)->whereNull('disabled_at')->whereIn('role', ['admin', 'agent'])->pluck('id')->all();
        expect(array_column($data, 'id'))->toEqualCanonicalizing($expected)->toContain($admin->id)->toContain($agent->id)->and(array_keys($data[0]))->toBe(['id', 'name', 'role']);
        expect(json_encode($data))->not->toContain('@');
    });
});
