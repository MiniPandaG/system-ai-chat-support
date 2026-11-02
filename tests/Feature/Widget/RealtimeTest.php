<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Broadcasting\Channels;
use App\Events\InboxChanged;
use App\Events\TicketChanged;
use App\Events\WidgetChanged;
use App\Models\Tenant;
use App\Tickets\TicketStatus;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

const WIDGET_KEY = 'llave-de-prueba';
const WIDGET_SECRET = 'secreto-de-prueba';

beforeEach(function (): void {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => WIDGET_KEY,
        'broadcasting.connections.reverb.secret' => WIDGET_SECRET,
        'broadcasting.connections.reverb.app_id' => '1',
    ]);
    // Los avisos se encolan sin enviarse: la autorización firma con credenciales de prueba y no conecta a ningún servidor.
    Queue::fake();
});

/**
 * @param  class-string  $event
 * @return Collection<int, object>
 */
function widgetAnnounced(string $event): Collection
{
    return Queue::pushed(BroadcastEvent::class)
        ->map(static fn (BroadcastEvent $job): object => $job->event)
        ->filter(static fn (object $candidate): bool => $candidate instanceof $event)
        ->values();
}

function widgetAuth(object $test, ?string $token, string $channel, string $socket = '123.456'): TestResponse
{
    return $test->postJson('/widget/api/broadcasting/auth', ['socket_id' => $socket, 'channel_name' => $channel], $token === null ? [] : ['Authorization' => 'Bearer '.$token]);
}

function ownWidgetChannel(object $test, string $token, Tenant $tenant): string
{
    return 'private-'.Channels::widget($tenant->id, widgetSessionOf($tenant, $token)->id);
}

describe('autorización del canal del visitante', function (): void {
    it('el visitante entra a su propio canal con una firma válida de esa conexión y de ese canal', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant);
        $channel = ownWidgetChannel($this, $token, $tenant);

        $auth = widgetAuth($this, $token, $channel, '111.222')->assertOk()->json('auth');
        $other = widgetAuth($this, $token, $channel, '999.888')->json('auth');

        expect($auth)->toBe(WIDGET_KEY.':'.hash_hmac('sha256', "111.222:{$channel}", WIDGET_SECRET))->not->toBe($other);
    });

    it('no firma el canal de otro visitante, aunque sea de la misma empresa', function (): void {
        $tenant = widgetTenant();
        $ana = widgetStart($this, $tenant);
        $beto = widgetStart($this, $tenant);

        widgetAuth($this, $ana, ownWidgetChannel($this, $beto, $tenant))->assertForbidden();
    });

    it('no firma el canal de un visitante de otra empresa', function (): void {
        $mine = widgetTenant();
        $theirs = widgetTenant();
        $token = widgetStart($this, $mine);
        $theirToken = widgetStart($this, $theirs);

        widgetAuth($this, $token, ownWidgetChannel($this, $theirToken, $theirs))->assertForbidden();
    });

    it('no firma ningún canal del equipo: ni la bandeja, ni un ticket, ni presencia, ni sin prefijo', function (string $channel): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant);
        $ticket = widgetTicketOf($tenant, $token);
        $session = widgetSessionOf($tenant, $token);

        $names = [
            'inbox' => 'private-'.Channels::inbox($tenant->id),
            'ticket' => 'private-'.Channels::ticket($tenant->id, $ticket->id),
            'presence' => 'presence-'.Channels::widget($tenant->id, $session->id),
            'sin prefijo' => Channels::widget($tenant->id, $session->id),
            'prefijo en medio' => 'private-x-private-'.Channels::widget($tenant->id, $session->id),
            'sobrante' => 'private-'.Channels::widget($tenant->id, $session->id).'.extra',
            'mayúsculas' => strtoupper('private-'.Channels::widget($tenant->id, $session->id)),
        ];

        widgetAuth($this, $token, $names[$channel])->assertForbidden();
    })->with(['inbox', 'ticket', 'presence', 'sin prefijo', 'prefijo en medio', 'sobrante', 'mayúsculas']);

    it('sin token recibe 401 y con un socket mal formado 403', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant);
        $channel = ownWidgetChannel($this, $token, $tenant);

        widgetAuth($this, null, $channel)->assertUnauthorized();
        widgetAuth($this, $token, $channel, 'no-es-un-socket')->assertForbidden();
        widgetAuth($this, $token, $channel, "123.4\n56")->assertForbidden();
        widgetAuth($this, $token, $channel, '12a.456')->assertForbidden();
    });

    it('sin llave de tiempo real configurada no firma nada', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant);
        config(['broadcasting.connections.reverb.key' => '', 'broadcasting.connections.reverb.secret' => '']);

        widgetAuth($this, $token, ownWidgetChannel($this, $token, $tenant))->assertForbidden();
    });

    it('el nombre del canal lleva la empresa y es privado', function (): void {
        expect(Channels::widget(5, '01abc'))->toBe('tenant.5.widget.01abc');
    });
});

describe('avisos al visitante', function (): void {
    it('cuando el equipo responde, avisa al canal privado de esa sesión y no lleva contenido', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant);
        $ticket = widgetTicketOf($tenant, $token);
        Queue::fake();

        sendReply($this, ticketStaff($tenant), $ticket, ['body' => 'Texto que no debe viajar'])->assertRedirect();

        $events = widgetAnnounced(WidgetChanged::class);
        expect($events)->toHaveCount(1);
        $event = $events->first();
        expect(array_map(static fn (PrivateChannel $channel): string => $channel->name, $event->broadcastOn()))
            ->toBe(['private-'.Channels::widget($tenant->id, widgetSessionOf($tenant, $token)->id)])
            ->and($event->broadcastWith())->toBe([])
            ->and($event->broadcastAs())->toBe('widget.changed');
    });

    it('al cambiar el estado del ticket avisa al visitante; al cambiar la prioridad o asignarlo, no', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant);
        $ticket = widgetTicketOf($tenant, $token);
        $agent = ticketStaff($tenant);
        Queue::fake();

        $this->actingAs($agent)->patch("/tickets/{$ticket->id}", ['priority' => 'high'])->assertRedirect();
        expect(widgetAnnounced(WidgetChanged::class))->toHaveCount(0);

        $this->actingAs($agent)->patch("/tickets/{$ticket->id}", ['status' => TicketStatus::Resolved->value])->assertRedirect();
        expect(widgetAnnounced(WidgetChanged::class))->toHaveCount(1);
    });

    it('las notas internas y las etiquetas no avisan al visitante', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant);
        $ticket = widgetTicketOf($tenant, $token);
        $agent = ticketStaff($tenant);
        Queue::fake();

        $this->actingAs($agent)->post("/tickets/{$ticket->id}/notes", ['body' => 'Nota interna'])->assertRedirect();
        $this->actingAs($agent)->post("/tickets/{$ticket->id}/tags", ['name' => 'VIP', 'tone' => 'primary'])->assertRedirect();

        expect(widgetAnnounced(WidgetChanged::class))->toHaveCount(0);
    });

    it('un ticket del panel no avisa a ningún widget', function (): void {
        $tenant = widgetTenant();
        $ticket = makeTicket($tenant);
        Queue::fake();

        sendReply($this, ticketStaff($tenant), $ticket, ['body' => 'Respuesta'])->assertRedirect();

        expect(widgetAnnounced(WidgetChanged::class))->toHaveCount(0);
    });

    it('cuando escribe el visitante avisa al equipo (ticket y bandeja) y a sus otras pestañas', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant);
        Queue::fake();

        widgetSend($this, $tenant, ['body' => 'Otro mensaje'], $token)->assertOk();

        expect(widgetAnnounced(TicketChanged::class))->toHaveCount(1)
            ->and(widgetAnnounced(InboxChanged::class))->toHaveCount(1)
            ->and(widgetAnnounced(WidgetChanged::class))->toHaveCount(1);
    });

    it('repetir un mensaje ya recibido no vuelve a avisar', function (): void {
        $tenant = widgetTenant();
        $clientId = (string) Str::uuid();
        $token = widgetStart($this, $tenant, ['client_id' => $clientId]);
        Queue::fake();

        widgetSend($this, $tenant, ['client_id' => $clientId], $token)->assertOk();

        expect(Queue::pushed(BroadcastEvent::class))->toHaveCount(0);
    });

    it('el aviso es privado y sale después de confirmar la transacción', function (): void {
        $event = new WidgetChanged(3, 'abc');

        expect($event)->toBeInstanceOf(ShouldBroadcast::class)->toBeInstanceOf(ShouldDispatchAfterCommit::class)
            ->and($event->broadcastOn()[0])->toBeInstanceOf(PrivateChannel::class);
    });
});
