<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Models\Tenant;
use App\Models\Ticket;
use App\Tickets\TicketStatus;
use Illuminate\Testing\TestResponse;

const WIDGET_THROTTLED = 'Estás enviando demasiado rápido. Espera un momento e inténtalo de nuevo.';

/** Envía como si viniera de esa dirección IP. */
function widgetFrom(object $test, string $ip, Closure $request): mixed
{
    return $request($test->withServerVariables(['REMOTE_ADDR' => $ip]));
}

function widgetSendFrom(object $test, string $ip, Tenant $tenant, array $payload = [], ?string $token = null): TestResponse
{
    return widgetFrom($test, $ip, fn ($client) => widgetSend($client, $tenant, $payload, $token));
}

describe('abrir conversaciones', function (): void {
    it('una misma dirección solo abre diez conversaciones por hora', function (): void {
        config(['widget.limits.tickets_per_ip_per_day' => 1000]);
        $tenant = widgetTenant();

        foreach (range(1, 10) as $_) {
            widgetSendFrom($this, '203.0.113.7', $tenant)->assertCreated();
        }
        $blocked = widgetSendFrom($this, '203.0.113.7', $tenant);

        $blocked->assertStatus(429);
        expect($blocked->json('message'))->toBe(WIDGET_THROTTLED)
            ->and(ticketsOf($tenant))->toHaveCount(10);
        widgetSendFrom($this, '203.0.113.8', $tenant)->assertCreated();
    });

    it('una empresa tiene un tope de conversaciones nuevas por hora, vengan de donde vengan', function (): void {
        config(['widget.limits.start_per_key_per_hour' => 3]);
        $tenant = widgetTenant();
        $other = widgetTenant();

        foreach (range(1, 3) as $number) {
            widgetSendFrom($this, "198.51.100.{$number}", $tenant)->assertCreated();
        }

        widgetSendFrom($this, '198.51.100.9', $tenant)->assertStatus(429);
        widgetSendFrom($this, '198.51.100.9', $other)->assertCreated();
        expect(ticketsOf($tenant))->toHaveCount(3);
    });

    it('una dirección tiene un tope diario de tickets nuevos aunque cambie de sesión', function (): void {
        config(['widget.limits.tickets_per_ip_per_day' => 2]);
        $tenant = widgetTenant();

        widgetSendFrom($this, '203.0.113.20', $tenant)->assertCreated();
        widgetSendFrom($this, '203.0.113.20', $tenant)->assertCreated();
        $blocked = widgetSendFrom($this, '203.0.113.20', $tenant);

        $blocked->assertStatus(429);
        expect($blocked->json('message'))->toBe('Ya abriste varias conversaciones hoy. Vuelve a intentarlo más tarde.')
            ->and(ticketsOf($tenant))->toHaveCount(2);
    });

    it('una sesión tiene un tope diario de tickets nuevos, aunque cierren los anteriores', function (): void {
        config(['widget.limits.tickets_per_ip_per_day' => 1000, 'widget.limits.messages_per_ip_per_minute' => 1000]);
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant);

        // Tres tickets al día por sesión: el que abre la sesión y dos más tras terminar el anterior.
        foreach (range(1, 2) as $_) {
            widgetTicketOf($tenant, $token)->forceFill(['status' => TicketStatus::Closed])->save();
            widgetSend($this, $tenant, [], $token)->assertOk();
        }
        widgetTicketOf($tenant, $token)->forceFill(['status' => TicketStatus::Closed])->save();

        widgetSend($this, $tenant, [], $token)->assertStatus(429);

        expect(ticketsOf($tenant))->toHaveCount(3);
    });

    it('un rechazo por límite no crea sesión, cliente ni ticket', function (): void {
        config(['widget.limits.start_per_ip_per_hour' => 1]);
        $tenant = widgetTenant();
        widgetSendFrom($this, '203.0.113.30', $tenant)->assertCreated();

        widgetSendFrom($this, '203.0.113.30', $tenant)->assertStatus(429);

        expect(ticketsOf($tenant))->toHaveCount(1);
    });
});

describe('escribir en una conversación', function (): void {
    it('una sesión tiene un tope de mensajes por minuto', function (): void {
        config(['widget.limits.messages_per_session_per_minute' => 2]);
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant);

        widgetSend($this, $tenant, [], $token)->assertOk();
        widgetSend($this, $tenant, [], $token)->assertOk();
        $blocked = widgetSend($this, $tenant, [], $token);

        $blocked->assertStatus(429);
        expect($blocked->json('message'))->toBe(WIDGET_THROTTLED);
        $this->travel(61)->seconds();
        widgetSend($this, $tenant, [], $token)->assertOk();
    });

    it('una dirección tiene un tope de mensajes por minuto entre todas sus sesiones', function (): void {
        config(['widget.limits.messages_per_ip_per_minute' => 1, 'widget.limits.start_per_ip_per_hour' => 1000, 'widget.limits.tickets_per_ip_per_day' => 1000]);
        $tenant = widgetTenant();
        $one = widgetStart($this, $tenant);
        $two = widgetStart($this, $tenant);

        widgetSend($this, $tenant, [], $one)->assertOk();
        widgetSend($this, $tenant, [], $two)->assertStatus(429);
    });

    it('un visitante limitado no afecta a otro de otra dirección', function (): void {
        config(['widget.limits.messages_per_session_per_minute' => 1]);
        $tenant = widgetTenant();
        $ana = widgetStart($this, $tenant);
        $beto = widgetFrom($this, '203.0.113.50', fn ($client) => widgetStart($client, $tenant));

        widgetSend($this, $tenant, [], $ana)->assertOk();
        widgetSend($this, $tenant, [], $ana)->assertStatus(429);
        widgetFrom($this, '203.0.113.50', fn ($client) => widgetSend($client, $tenant, [], $beto))->assertOk();
        widgetFrom($this, '203.0.113.50', fn ($client) => widgetSend($client, $tenant, [], $beto))->assertStatus(429);
        $this->travel(61)->seconds();
        widgetFrom($this, '203.0.113.50', fn ($client) => widgetSend($client, $tenant, [], $beto))->assertOk();
    });
});

describe('las demás rutas', function (): void {
    it('leer la conversación, dejar el contacto y firmar el canal tienen su tope', function (string $setting, Closure $call): void {
        config(["widget.limits.{$setting}" => 2]);
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant);

        $call($this, $token)->assertSuccessful();
        $call($this, $token)->assertSuccessful();
        $blocked = $call($this, $token);

        $blocked->assertStatus(429);
        expect($blocked->json('message'))->toBe(WIDGET_THROTTLED);
    })->with([
        'leer' => ['reads_per_session_per_minute', fn ($test, $token) => widgetGet($test, 'conversation', $token)],
        'contacto' => ['contact_per_session_per_hour', fn ($test, $token) => $test->postJson('/widget/api/contact', ['email' => 'a@b.com'], ['Authorization' => 'Bearer '.$token])],
    ]);

    it('cargar el marco tiene un tope por dirección', function (): void {
        $this->withoutVite();
        config(['widget.limits.frames_per_ip_per_minute' => 2]);
        $tenant = widgetTenant();
        $url = '/widget/frame?key='.$tenant->widget_key.'&parent='.urlencode('https://tienda-ejemplo.com');

        $this->get($url)->assertOk();
        $this->get($url)->assertOk();
        $this->get($url)->assertStatus(429);
    });

    it('la respuesta de un límite no revela cuánto falta ni nada de la empresa', function (): void {
        config(['widget.limits.start_per_ip_per_hour' => 1]);
        $tenant = widgetTenant();
        widgetSend($this, $tenant)->assertCreated();

        $blocked = widgetSend($this, $tenant);

        expect(array_keys($blocked->json()))->toBe(['message'])
            ->and($blocked->getContent())->not->toContain($tenant->name)->not->toContain((string) $tenant->id);
    });
});

describe('errores que no filtran detalles', function (): void {
    it('una petición mal formada responde con un error de validación, no con una traza', function (): void {
        $tenant = widgetTenant();

        $response = $this->call('POST', '/widget/api/messages', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{no es json');

        $response->assertUnprocessable();
        expect($response->getContent())->not->toContain('vendor')->not->toContain('Exception')->not->toContain(base_path());
    });

    it('un método no permitido responde 405 sin detalles internos', function (): void {
        $response = $this->getJson('/widget/api/messages');

        $response->assertStatus(405);
        expect($response->getContent())->not->toContain('vendor')->not->toContain(base_path());
    });

    it('un ticket ajeno no se puede alcanzar con una sesión por ningún identificador del cuerpo', function (): void {
        $tenant = widgetTenant();
        $victim = widgetStart($this, $tenant, ['body' => 'Mensaje privado de la víctima']);
        $victimTicket = widgetTicketOf($tenant, $victim);
        $attacker = widgetStart($this, $tenant);

        widgetSend($this, $tenant, ['ticket_id' => $victimTicket->id, 'body' => 'Intento colarme'], $attacker)->assertOk();

        expect(widgetBodies($tenant, $victimTicket))->toBe(['Mensaje privado de la víctima'])
            ->and(Ticket::query()->withoutGlobalScopes()->count())->toBe(2);
    });
});
