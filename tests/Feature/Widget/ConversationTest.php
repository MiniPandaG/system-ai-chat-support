<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Enums\Role;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\WidgetSession;
use App\Tenancy\TenantContext;
use App\Tickets\Channel;
use App\Tickets\MessageAuthor;
use App\Tickets\TicketStatus;
use App\Widget\SessionToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

describe('abrir una conversación', function (): void {
    it('el primer mensaje crea la sesión, el cliente y un ticket del canal widget con ese mensaje', function (): void {
        $tenant = widgetTenant();

        $response = widgetSend($this, $tenant, ['body' => "  Mi pedido no llegó\nlo hice el lunes  "])->assertCreated();

        $token = $response->json('token');
        $ticket = widgetTicketOf($tenant, $token);
        expect($ticket->channel)->toBe(Channel::Widget)
            ->and($ticket->status)->toBe(TicketStatus::Open)
            ->and($ticket->subject)->toBe('Mi pedido no llegó lo hice el lunes')
            ->and($ticket->number)->toBe(1)
            ->and($ticket->created_by)->toBeNull()
            ->and($ticket->assigned_to)->toBeNull()
            ->and($ticket->tenant_id)->toBe($tenant->id);

        app(TenantContext::class)->run($tenant, function () use ($ticket): void {
            $message = TicketMessage::query()->where('ticket_id', $ticket->id)->sole();
            expect($message->author)->toBe(MessageAuthor::Customer)
                ->and($message->user_id)->toBeNull()
                ->and($message->widget_session_id)->not->toBeNull();
        });

        expect($response->json('message.author'))->toBe('customer')
            ->and($response->json('message.body'))->toContain('Mi pedido no llegó');
    });

    it('el visitante se llama «Visitante» seguido de un código corto si no da su nombre', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant);

        $name = widgetCustomerOf($tenant, widgetTicketOf($tenant, $token))->name;

        expect($name)->toMatch('/\AVisitante [0-9A-Z]{4}\z/');
    });

    it('con nombre y correo en el primer mensaje los guarda en el cliente', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant, ['name' => ' Marisol Quintero ', 'email' => 'Marisol@Cliente-Ejemplo.com']);

        $customer = widgetCustomerOf($tenant, widgetTicketOf($tenant, $token));

        expect($customer->name)->toBe('Marisol Quintero')->and($customer->email)->toBe('marisol@cliente-ejemplo.com');
    });

    it('el token solo existe en la respuesta: en la base queda su huella', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant);

        $session = widgetSessionOf($tenant, $token);

        expect($token)->toMatch('/\A'.$tenant->id.'\.[A-Za-z0-9]{43}\z/')
            ->and($session?->token_hash)->toBe(hash('sha256', $token));

        $dump = json_encode([
            DB::table('widget_sessions')->get()->all(),
            DB::table('ticket_messages')->get()->all(),
            DB::table('customers')->get()->all(),
            DB::table('audit_logs')->get()->all(),
        ]);
        expect($dump)->not->toContain(substr($token, strpos($token, '.') + 1));
    });

    it('cada sesión recibe un token distinto', function (): void {
        $tenant = widgetTenant();

        expect(widgetStart($this, $tenant))->not->toBe(widgetStart($this, $tenant));
    });

    it('la respuesta no trae más token cuando la sesión ya existía', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant);

        $second = widgetSend($this, $tenant, ['body' => 'Sigo esperando'], $token)->assertOk();

        expect($second->json('token'))->toBeNull();
    });
});

describe('seguir la conversación', function (): void {
    it('los siguientes mensajes van al mismo ticket y mueven la fecha del último mensaje', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant, ['body' => 'Primero']);
        $ticket = widgetTicketOf($tenant, $token);
        $this->travel(5)->minutes();

        widgetSend($this, $tenant, ['body' => 'Segundo'], $token)->assertOk();

        expect(widgetBodies($tenant, $ticket))->toBe(['Primero', 'Segundo'])
            ->and(ticketsOf($tenant))->toHaveCount(1)
            ->and(reloaded($ticket)->last_message_at->isAfter($ticket->last_message_at))->toBeTrue();
    });

    it('repetir el mismo identificador de mensaje no lo duplica y devuelve el original', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant, ['body' => 'Primero']);
        $clientId = (string) Str::uuid();

        $first = widgetSend($this, $tenant, ['client_id' => $clientId, 'body' => 'Una vez'], $token)->assertOk();
        $again = widgetSend($this, $tenant, ['client_id' => $clientId, 'body' => 'Una vez'], $token)->assertOk();

        expect($again->json('message.id'))->toBe($first->json('message.id'))
            ->and(widgetBodies($tenant, widgetTicketOf($tenant, $token)))->toBe(['Primero', 'Una vez']);
    });

    it('repetir el identificador del mensaje que abrió la conversación tampoco crea otro ticket', function (): void {
        $tenant = widgetTenant();
        $clientId = (string) Str::uuid();
        $token = widgetStart($this, $tenant, ['client_id' => $clientId]);

        widgetSend($this, $tenant, ['client_id' => $clientId], $token)->assertOk();

        expect(ticketsOf($tenant))->toHaveCount(1);
    });

    it('escribir cuando el equipo esperaba al cliente devuelve el ticket a abierto', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant);
        $ticket = widgetTicketOf($tenant, $token);
        $ticket->forceFill(['status' => TicketStatus::WaitingCustomer])->save();

        widgetSend($this, $tenant, ['body' => 'Ya lo revisé'], $token)->assertOk();

        expect(reloaded($ticket)->status)->toBe(TicketStatus::Open);
    });

    it('si el ticket se resolvió o se cerró, el siguiente mensaje abre un ticket nuevo', function (TicketStatus $ended): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant, ['body' => 'Primera consulta']);
        $first = widgetTicketOf($tenant, $token);
        $first->forceFill(['status' => $ended])->save();

        widgetSend($this, $tenant, ['body' => 'Otra consulta'], $token)->assertOk();

        $second = widgetTicketOf($tenant, $token);
        expect($second->id)->not->toBe($first->id)
            ->and($second->number)->toBe(2)
            ->and(widgetBodies($tenant, $first))->toBe(['Primera consulta'])
            ->and(widgetBodies($tenant, $second))->toBe(['Otra consulta']);
    })->with([TicketStatus::Resolved, TicketStatus::Closed]);
});

describe('lo que el visitante envía', function (): void {
    it('rechaza texto vacío, solo espacios, demasiado largo o con caracteres de control', function (string $body): void {
        $tenant = widgetTenant();

        widgetSend($this, $tenant, ['body' => $body])->assertUnprocessable()->assertJsonValidationErrors('body');

        expect(ticketsOf($tenant))->toHaveCount(0);
    })->with([
        'vacío' => [''],
        'espacios' => ["   \n\t "],
        'largo' => [str_repeat('a', 2001)],
        'control' => ["hola\x00mundo"],
    ]);

    it('acepta el tope exacto de caracteres', function (): void {
        $tenant = widgetTenant();

        widgetSend($this, $tenant, ['body' => str_repeat('a', 2000)])->assertCreated();
    });

    it('exige un identificador de mensaje con forma de UUID', function (): void {
        $tenant = widgetTenant();

        widgetSend($this, $tenant, ['client_id' => 'no-es-un-uuid'])->assertUnprocessable()->assertJsonValidationErrors('client_id');
        widgetSend($this, $tenant, ['client_id' => null])->assertUnprocessable()->assertJsonValidationErrors('client_id');
    });

    it('rechaza un correo mal escrito y un nombre con caracteres de control, con mensajes en español', function (): void {
        $tenant = widgetTenant();

        widgetSend($this, $tenant, ['email' => 'no-es-correo'])->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Escribe un correo válido, por ejemplo nombre@empresa.com.');
        widgetSend($this, $tenant, ['name' => "Ana\x07"])->assertUnprocessable()->assertJsonValidationErrors('name');
    });

    it('guarda el texto tal cual, sin interpretarlo: HTML y marcas se quedan como texto', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant, ['body' => '<script>alert(1)</script> **negrita** <b>x</b>']);

        expect(widgetBodies($tenant, widgetTicketOf($tenant, $token)))->toBe(['<script>alert(1)</script> **negrita** <b>x</b>']);
    });

    it('lo que el cliente intente fijar por su cuenta se ignora: empresa, autor, ticket, estado y canal', function (): void {
        $tenant = widgetTenant();
        $other = widgetTenant();
        $token = widgetStart($this, $tenant, [
            'tenant_id' => $other->id,
            'author' => 'agent',
            'user_id' => 1,
            'ticket_id' => 999,
            'status' => 'closed',
            'channel' => 'panel',
            'priority' => 'urgent',
        ]);

        $ticket = widgetTicketOf($tenant, $token);
        expect($ticket->tenant_id)->toBe($tenant->id)
            ->and($ticket->channel)->toBe(Channel::Widget)
            ->and($ticket->status)->toBe(TicketStatus::Open)
            ->and(ticketsOf($other))->toHaveCount(0);
    });
});

describe('la llave pública y el estado del widget', function (): void {
    it('una llave que no existe y un widget sin sitios permitidos reciben lo mismo: 404', function (): void {
        $off = widgetTenant(enabled: false);

        $unknown = widgetSend($this, $off, ['key' => str_repeat('x', 32)])->assertNotFound();
        $disabled = widgetSend($this, $off)->assertNotFound();

        expect($unknown->json())->toBe($disabled->json());
    });

    it('una llave con mala forma no llega a la base de datos', function (string $key): void {
        $tenant = widgetTenant();

        widgetSend($this, $tenant, ['key' => $key])->assertUnprocessable();
    })->with(['corta' => ['abc'], 'con símbolos' => [str_repeat('a', 31).'%'], 'larga' => [str_repeat('a', 33)]]);

    it('la llave nace con la empresa, es de 32 caracteres y distinta en cada una', function (): void {
        $first = widgetTenant();
        $second = widgetTenant();

        expect($first->widget_key)->toMatch('/\A[A-Za-z0-9]{32}\z/')->and($first->widget_key)->not->toBe($second->widget_key);
    });

    it('al apagar el widget (sin sitios permitidos) las sesiones existentes dejan de servir', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant);

        app(TenantContext::class)->run($tenant, fn () => DB::table('widget_origins')->delete());

        widgetGet($this, 'conversation', $token)->assertUnauthorized();
        widgetSend($this, $tenant, ['body' => 'Hola'], $token)->assertUnauthorized();
    });
});

describe('el token de la sesión', function (): void {
    it('sin token, con mala forma o desconocido recibe 401', function (?string $token): void {
        widgetTenant();

        widgetGet($this, 'conversation', $token)->assertUnauthorized();
    })->with([
        'ausente' => [null],
        'vacío' => [''],
        'sin empresa' => [str_repeat('a', 43)],
        'con comillas' => ["1.' OR 1=1 --".str_repeat('a', 30)],
        'corto' => ['1.abc'],
        'empresa inexistente' => ['999999.'.'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ'],
    ]);

    it('un token con la empresa cambiada no sirve en la otra empresa', function (): void {
        $mine = widgetTenant();
        $theirs = widgetTenant();
        $token = widgetStart($this, $mine);
        $forged = $theirs->id.substr($token, strpos($token, '.'));

        widgetGet($this, 'conversation', $forged)->assertUnauthorized();
    });

    it('una sesión caducada recibe 401', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant);
        $this->travel(25)->hours();

        widgetGet($this, 'conversation', $token)->assertUnauthorized();
        widgetSend($this, $tenant, ['body' => 'Hola otra vez'], $token)->assertUnauthorized();
    });

    it('la actividad alarga la sesión, pero nunca más de siete días desde que nació', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant);

        // Cada 22 horas: dentro de la inactividad permitida, así que solo el tope de siete días (168 h) corta la sesión.
        foreach (range(1, 7) as $step) {
            $this->travel(22)->hours();
            widgetSend($this, $tenant, ['body' => "Mensaje {$step}"], $token)->assertOk();
        }

        $this->travel(22)->hours();
        widgetGet($this, 'conversation', $token)->assertUnauthorized();
    });

    it('un token inválido enviado al abrir una conversación es un 401, no se ignora', function (): void {
        $tenant = widgetTenant();

        widgetSend($this, $tenant, [], 'basura')->assertUnauthorized();

        expect(ticketsOf($tenant))->toHaveCount(0);
    });
});

describe('la conversación que ve el visitante', function (): void {
    it('devuelve sus mensajes y las respuestas del equipo con el primer nombre', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant, ['body' => '¿Hacen envíos?']);
        $ticket = widgetTicketOf($tenant, $token);
        $agent = ticketStaff($tenant);
        $agent->forceFill(['name' => 'Laura Gómez Pérez'])->save();
        sendReply($this, $agent, $ticket, ['body' => 'Sí, a todo el país.'])->assertRedirect();

        $conversation = widgetGet($this, 'conversation', $token)->assertOk();

        expect(array_column($conversation->json('messages'), 'body'))->toBe(['¿Hacen envíos?', 'Sí, a todo el país.'])
            ->and(array_column($conversation->json('messages'), 'author'))->toBe(['customer', 'agent'])
            ->and($conversation->json('messages.1.agent'))->toBe('Laura')
            ->and($conversation->json('messages.0.agent'))->toBeNull()
            ->and($conversation->json('ticket'))->toBe(['status' => 'open', 'ended' => false])
            ->and($conversation->json('contact'))->toBe(['has_email' => false]);
    });

    it('nunca incluye las notas internas, ni su texto ni su autor', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant);
        $ticket = widgetTicketOf($tenant, $token);
        $agent = ticketStaff($tenant);
        makeNote($ticket, $agent, ['body' => 'NOTA-SECRETA-DEL-EQUIPO el cliente miente']);
        sendReply($this, $agent, $ticket, ['body' => 'Respuesta pública'])->assertRedirect();

        $response = widgetGet($this, 'conversation', $token)->assertOk();

        expect($response->getContent())->not->toContain('NOTA-SECRETA')
            ->and(array_column($response->json('messages'), 'body'))->toContain('Respuesta pública');
    });

    it('no incluye identificadores internos de la empresa, el cliente, el ticket ni del equipo', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant);

        $keys = array_keys(widgetGet($this, 'conversation', $token)->json());
        $message = array_keys(widgetGet($this, 'conversation', $token)->json('messages.0'));

        expect($keys)->toBe(['channel', 'ticket', 'messages', 'contact'])
            ->and($message)->toBe(['id', 'client_id', 'author', 'body', 'sent_at', 'agent']);
    });

    it('un visitante solo ve su propia conversación', function (): void {
        $tenant = widgetTenant();
        $ana = widgetStart($this, $tenant, ['body' => 'Soy Ana, mi número es 111']);
        $beto = widgetStart($this, $tenant, ['body' => 'Soy Beto, mi número es 222']);

        $forAna = widgetGet($this, 'conversation', $ana)->getContent();
        $forBeto = widgetGet($this, 'conversation', $beto)->getContent();

        expect($forAna)->toContain('111')->not->toContain('222')
            ->and($forBeto)->toContain('222')->not->toContain('111');
    });

    it('sin conversación todavía devuelve una vacía', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant);
        app(TenantContext::class)->run($tenant, fn () => WidgetSession::query()->update(['ticket_id' => null]));

        $conversation = widgetGet($this, 'conversation', $token)->assertOk();

        expect($conversation->json('ticket'))->toBeNull()->and($conversation->json('messages'))->toBe([]);
    });

    it('no se guarda en caché', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant);

        expect(widgetGet($this, 'conversation', $token)->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('al terminar el ticket avisa de que la conversación terminó', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant);
        $ticket = widgetTicketOf($tenant, $token);
        $ticket->forceFill(['status' => TicketStatus::Resolved])->save();

        expect(widgetGet($this, 'conversation', $token)->json('ticket'))->toBe(['status' => 'resolved', 'ended' => true]);
    });
});

describe('el equipo ve el chat web en su bandeja', function (): void {
    it('el ticket aparece en la bandeja con su canal y su primer mensaje', function (): void {
        $tenant = widgetTenant();
        widgetStart($this, $tenant, ['body' => 'Quiero cambiar mi pedido']);
        $agent = ticketStaff($tenant);

        $response = $this->actingAs($agent)->get('/tickets')->assertOk();
        $detail = $this->actingAs($agent)->get('/tickets/'.widgetTicketOf($tenant, widgetStart($this, $tenant))->id)->assertOk();

        expect(inboxSubjects($response))->toBe(['Quiero cambiar mi pedido'])
            ->and(inboxProp($detail, 'selected.channel'))->toBe('widget');
    });

    it('el equipo responde y el visitante ve la respuesta; el observador no puede responder', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant);
        $ticket = widgetTicketOf($tenant, $token);

        sendReply($this, ticketStaff($tenant, Role::Observer), $ticket, ['body' => 'No debería'])->assertForbidden();
        sendReply($this, ticketStaff($tenant), $ticket, ['body' => 'Con gusto te ayudo'])->assertRedirect();

        expect(array_column(widgetGet($this, 'conversation', $token)->json('messages'), 'body'))
            ->toBe(['Hola, necesito ayuda con mi pedido.', 'Con gusto te ayudo']);
    });
});

describe('aislamiento entre empresas', function (): void {
    it('el ticket del widget de otra empresa da 404 al equipo', function (): void {
        $mine = widgetTenant();
        $theirs = widgetTenant();
        $token = widgetStart($this, $theirs);
        $ticket = widgetTicketOf($theirs, $token);

        $this->actingAs(ticketStaff($mine))->get("/tickets/{$ticket->id}")->assertNotFound();
    });

    it('cada empresa numera sus tickets del widget por separado', function (): void {
        $first = widgetTenant();
        $second = widgetTenant();

        widgetStart($this, $first);
        widgetStart($this, $first);
        $token = widgetStart($this, $second);

        expect(widgetTicketOf($second, $token)->number)->toBe(1)
            ->and(ticketsOf($first)->pluck('number')->all())->toBe([1, 2]);
    });

    it('el cliente y la sesión del widget no se ven desde otra empresa', function (): void {
        $mine = widgetTenant();
        $theirs = widgetTenant();
        widgetStart($this, $theirs);

        app(TenantContext::class)->run($mine, function (): void {
            expect(Customer::query()->count())->toBe(0)->and(WidgetSession::query()->count())->toBe(0);
        });
    });
});

describe('el límite de tickets del plan', function (): void {
    it('con el tope alcanzado el visitante ve un aviso general que no revela el plan ni el tope', function (): void {
        $tenant = widgetTenant('inicial');
        DB::table('plans')->where('slug', 'inicial')->update(['max_tickets_per_month' => 1]);
        widgetStart($this, $tenant);

        $blocked = widgetSend($this, $tenant)->assertStatus(503);

        expect($blocked->json('message'))->toBe('El chat no está disponible en este momento. Inténtalo de nuevo más tarde.')
            ->and($blocked->getContent())->not->toContain('inicial')->not->toContain('plan')
            ->and(ticketsOf($tenant))->toHaveCount(1);
    });

    it('seguir en una conversación abierta no gasta otro ticket del plan', function (): void {
        $tenant = widgetTenant('inicial');
        DB::table('plans')->where('slug', 'inicial')->update(['max_tickets_per_month' => 1]);
        $token = widgetStart($this, $tenant);

        widgetSend($this, $tenant, ['body' => 'Otro mensaje'], $token)->assertOk();

        expect(ticketsOf($tenant))->toHaveCount(1);
    });

    it('un rechazo por el tope no deja una sesión ni un cliente huérfanos', function (): void {
        $tenant = widgetTenant('inicial');
        DB::table('plans')->where('slug', 'inicial')->update(['max_tickets_per_month' => 0]);

        widgetSend($this, $tenant)->assertStatus(503);

        app(TenantContext::class)->run($tenant, function (): void {
            expect(WidgetSession::query()->count())->toBe(0)->and(Customer::query()->count())->toBe(0);
        });
    });
});

describe('dejar el nombre y el correo', function (): void {
    it('guarda el correo y el nombre y lo refleja en la conversación', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant);

        $this->postJson('/widget/api/contact', ['name' => 'Marisol', 'email' => 'Marisol@Cliente.com'], ['Authorization' => 'Bearer '.$token])
            ->assertOk()->assertJson(['saved' => true]);

        $customer = widgetCustomerOf($tenant, widgetTicketOf($tenant, $token));
        expect($customer->name)->toBe('Marisol')->and($customer->email)->toBe('marisol@cliente.com')
            ->and(widgetGet($this, 'conversation', $token)->json('contact'))->toBe(['has_email' => true]);
    });

    it('un correo que ya es de otro cliente de la empresa no se vincula y la respuesta es la misma', function (): void {
        $tenant = widgetTenant();
        app(TenantContext::class)->run($tenant, fn () => Customer::factory()->create(['email' => 'victima@cliente.com']));
        $token = widgetStart($this, $tenant);

        $response = $this->postJson('/widget/api/contact', ['email' => 'victima@cliente.com'], ['Authorization' => 'Bearer '.$token]);

        $response->assertOk()->assertJson(['saved' => true]);
        expect(widgetCustomerOf($tenant, widgetTicketOf($tenant, $token))->email)->toBeNull();
    });

    it('el correo ya dado no se puede cambiar por otro', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant, ['email' => 'primero@cliente.com']);

        $this->postJson('/widget/api/contact', ['email' => 'segundo@cliente.com'], ['Authorization' => 'Bearer '.$token])->assertOk();

        expect(widgetCustomerOf($tenant, widgetTicketOf($tenant, $token))->email)->toBe('primero@cliente.com');
    });

    it('exige nombre o correo y valida su forma', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant);
        $headers = ['Authorization' => 'Bearer '.$token];

        $this->postJson('/widget/api/contact', [], $headers)->assertUnprocessable();
        $this->postJson('/widget/api/contact', ['email' => 'mal'], $headers)->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/widget/api/contact', ['name' => str_repeat('a', 81)], $headers)->assertUnprocessable()->assertJsonValidationErrors('name');
    });

    it('sin sesión recibe 401', function (): void {
        widgetTenant();

        $this->postJson('/widget/api/contact', ['email' => 'a@b.com'])->assertUnauthorized();
    });
});

describe('la API del widget y las cookies', function (): void {
    it('no establece cookies ni usa la sesión del panel', function (): void {
        $tenant = widgetTenant();

        $response = widgetSend($this, $tenant)->assertCreated();

        expect($response->headers->getCookies())->toBe([]);
    });

    it('las cabeceras de seguridad globales siguen aplicándose', function (): void {
        $tenant = widgetTenant();

        $response = widgetSend($this, $tenant)->assertCreated();

        expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    });

    it('el token tiene la forma que el servidor reconoce y nada más', function (): void {
        expect(SessionToken::parse('5.'.str_repeat('a', 43)))->toBe(['tenant_id' => 5, 'hash' => hash('sha256', '5.'.str_repeat('a', 43))])
            ->and(SessionToken::parse('0.'.str_repeat('a', 43)))->toBeNull()
            ->and(SessionToken::parse('05.'.str_repeat('a', 43)))->toBeNull()
            ->and(SessionToken::parse("5.\n".str_repeat('a', 43)))->toBeNull()
            ->and(SessionToken::parse('5.'.str_repeat('a', 43)."\n"))->toBeNull()
            ->and(SessionToken::parse(null))->toBeNull();
    });
});

it('una conversación del widget cuenta como un ticket normal para la bandeja', function (): void {
    $tenant = widgetTenant();
    widgetStart($this, $tenant);

    app(TenantContext::class)->run($tenant, function (): void {
        expect(Ticket::query()->where('channel', Channel::Widget->value)->count())->toBe(1);
    });
});
