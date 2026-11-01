<?php

declare(strict_types=1);

require_once __DIR__.'/../Tickets/Helpers.php';

use App\Broadcasting\Channels;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Testing\TestResponse;

const REALTIME_KEY = 'llave-de-prueba';
const REALTIME_SECRET = 'secreto-de-prueba';

beforeEach(function (): void {
    // La autorización firma con el servidor real de Reverb: se configura con credenciales de prueba, sin conectar a nada.
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => REALTIME_KEY,
        'broadcasting.connections.reverb.secret' => REALTIME_SECRET,
        'broadcasting.connections.reverb.app_id' => '1',
    ]);
    // Los canales se registran al arrancar, en el servidor que había entonces (el nulo de las pruebas); se vuelven a registrar
    // en el servidor que se acaba de configurar.
    require base_path('routes/channels.php');
});

function authorizeChannel(object $test, ?User $user, string $channel, string $socket = '123.456'): TestResponse
{
    $request = $user === null ? $test : $test->actingAs($user);

    return $request->postJson('/broadcasting/auth', ['socket_id' => $socket, 'channel_name' => $channel]);
}

/** @return array{0: Tenant, 1: User, 2: Ticket} */
function realtimeSetup(Role $role = Role::Agent): array
{
    $tenant = ticketTenant();

    return [$tenant, ticketStaff($tenant, $role), makeTicket($tenant)];
}

describe('nombres de los canales', function (): void {
    it('llevan la empresa y el ticket, y solo existen como privados', function (): void {
        expect(Channels::inbox(5))->toBe('tenant.5.inbox')->and(Channels::ticket(5, 12))->toBe('tenant.5.tickets.12');
    });
});

describe('quién puede entrar', function (): void {
    it('un miembro activo de la empresa entra a la bandeja y a un ticket de su empresa, con cualquier rol', function (Role $role): void {
        [$tenant, $user, $ticket] = realtimeSetup($role);

        authorizeChannel($this, $user, 'private-'.Channels::inbox($tenant->id))->assertOk();
        authorizeChannel($this, $user, 'private-'.Channels::ticket($tenant->id, $ticket->id))->assertOk();
    })->with([Role::Admin, Role::Agent, Role::Observer]);

    it('la respuesta lleva una firma válida de esa conexión y de ese canal, y solo de ellos', function (): void {
        [$tenant, $user] = realtimeSetup();
        $channel = 'private-'.Channels::inbox($tenant->id);

        $auth = authorizeChannel($this, $user, $channel, '111.222')->assertOk()->json('auth');
        $other = authorizeChannel($this, $user, $channel, '999.888')->json('auth');

        expect($auth)->toBe(REALTIME_KEY.':'.hash_hmac('sha256', "111.222:{$channel}", REALTIME_SECRET))->not->toBe($other);
    });

    it('otra empresa no entra a la bandeja ni al ticket, ni escribiendo su propio identificador de empresa', function (): void {
        [$tenant, , $ticket] = realtimeSetup();
        $other = Tenant::factory()->onPlan('negocio')->create();
        $intruder = ticketStaff($other, Role::Admin);

        foreach ([Channels::inbox($tenant->id), Channels::ticket($tenant->id, $ticket->id), Channels::ticket($other->id, $ticket->id)] as $name) {
            authorizeChannel($this, $intruder, 'private-'.$name)->assertForbidden();
        }
    });

    it('un miembro no entra a la bandeja de otra empresa', function (): void {
        [, $user] = realtimeSetup();
        $other = Tenant::factory()->onPlan('negocio')->create();

        authorizeChannel($this, $user, 'private-'.Channels::inbox($other->id))->assertForbidden();
    });

    it('un ticket que no existe, o que es de otra empresa, no se puede abrir con el nombre de la propia', function (): void {
        [$tenant, $user] = realtimeSetup();
        $foreign = makeTicket(ticketTenant());

        authorizeChannel($this, $user, 'private-'.Channels::ticket($tenant->id, $foreign->id))->assertForbidden();
        authorizeChannel($this, $user, 'private-'.Channels::ticket($tenant->id, 999999))->assertForbidden();
    });

    it('un identificador mal formado no entra', function (string $channel): void {
        [, $user] = realtimeSetup();

        authorizeChannel($this, $user, $channel)->assertForbidden();
    })->with([
        'empresa con letras' => ['private-tenant.abc.inbox'],
        'empresa negativa' => ['private-tenant.-1.inbox'],
        'empresa en cero' => ['private-tenant.0.inbox'],
        'empresa decimal' => ['private-tenant.1.5.inbox'],
        'empresa enorme' => ['private-tenant.99999999999999999999.inbox'],
        'empresa con signo' => ['private-tenant.+1.inbox'],
        'ticket con letras' => ['private-tenant.1.tickets.abc'],
        'ticket en cero' => ['private-tenant.1.tickets.0'],
        'ticket en notación científica' => ['private-tenant.1.tickets.1e3'],
        'ticket vacío' => ['private-tenant.1.tickets.'],
    ]);

    it('un canal que no existe o que no es de empresa no se autoriza', function (string $channel): void {
        [$tenant, $user] = realtimeSetup();

        authorizeChannel($this, $user, str_replace('{t}', (string) $tenant->id, $channel))->assertForbidden();
    })->with([
        'otro nombre' => ['private-tenant.{t}.secretos'],
        'sin empresa' => ['private-inbox'],
        'canal general' => ['private-general'],
        'de presencia' => ['presence-tenant.{t}.inbox'],
        'con nombre de empresa de más' => ['private-tenant.{t}.inbox.extra'],
        'con barra' => ['private-tenant.{t}/inbox'],
    ]);

    it('un nombre sin el prefijo «private-» no da ninguna firma', function (): void {
        [$tenant, $user] = realtimeSetup();

        $response = authorizeChannel($this, $user, Channels::inbox($tenant->id));

        expect($response->json('auth'))->toBeNull();
    });
});

describe('quién no puede', function (): void {
    it('un visitante no entra', function (): void {
        [$tenant] = realtimeSetup();

        authorizeChannel($this, null, 'private-'.Channels::inbox($tenant->id))->assertUnauthorized();
    });

    it('un usuario desactivado no entra', function (): void {
        $tenant = ticketTenant();
        $user = ticketStaff($tenant, Role::Agent, disabled: true);

        authorizeChannel($this, $user, 'private-'.Channels::inbox($tenant->id))->assertRedirect(route('login'));
    });

    it('el personal de la plataforma no tiene empresa y no entra a ninguna', function (): void {
        [$tenant] = realtimeSetup();
        $staff = User::factory()->create(['is_platform_admin' => true]);
        $staff->forceFill(['two_factor_secret' => encrypt('S'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()])->save();

        authorizeChannel($this, $staff, 'private-'.Channels::inbox($tenant->id))->assertForbidden();
    });

    it('un administrador sin 2FA pasa primero por la pantalla de seguridad, como en el resto del panel', function (): void {
        $tenant = ticketTenant();
        $admin = User::factory()->forTenant($tenant, Role::Admin)->create();

        authorizeChannel($this, $admin, 'private-'.Channels::inbox($tenant->id))->assertRedirect(route('security'));
    });

    it('una cuenta sin correo verificado no entra', function (): void {
        $tenant = ticketTenant();
        $user = User::factory()->forTenant($tenant, Role::Agent)->unverified()->create();

        authorizeChannel($this, $user, 'private-'.Channels::inbox($tenant->id))->assertForbidden();
    });
});

describe('abuso', function (): void {
    it('limita las autorizaciones a 60 por minuto y por persona', function (): void {
        [$tenant, $user] = realtimeSetup();

        foreach (range(1, 60) as $ignored) {
            authorizeChannel($this, $user, 'private-'.Channels::inbox($tenant->id))->assertOk();
        }

        authorizeChannel($this, $user, 'private-'.Channels::inbox($tenant->id))->assertTooManyRequests();
    });

    it('el límite es de cada persona', function (): void {
        [$tenant, $busy] = realtimeSetup();
        $other = ticketStaff($tenant);
        foreach (range(1, 60) as $ignored) {
            authorizeChannel($this, $busy, 'private-'.Channels::inbox($tenant->id))->assertOk();
        }

        authorizeChannel($this, $busy, 'private-'.Channels::inbox($tenant->id))->assertTooManyRequests();
        authorizeChannel($this, $other, 'private-'.Channels::inbox($tenant->id))->assertOk();
    });

    it('pedir la firma sin los datos que hacen falta no rompe nada', function (array $payload): void {
        [, $user] = realtimeSetup();

        $response = $this->actingAs($user)->postJson('/broadcasting/auth', $payload);

        expect($response->status())->toBeLessThan(500)->and($response->json('auth'))->toBeNull();
    })->with([
        'vacío' => [[]],
        'sin conexión' => [['channel_name' => 'private-tenant.1.inbox']],
        'sin canal' => [['socket_id' => '1.2']],
        'canal como lista' => [['socket_id' => '1.2', 'channel_name' => ['a']]],
    ]);

    it('los errores de autorización no dicen si el ticket o la empresa existen', function (): void {
        [$tenant, $user] = realtimeSetup();
        $foreign = makeTicket(ticketTenant());

        $missing = authorizeChannel($this, $user, 'private-'.Channels::ticket($tenant->id, 999999));
        $foreignResponse = authorizeChannel($this, $user, 'private-'.Channels::ticket($tenant->id, $foreign->id));

        expect($missing->status())->toBe(403)->and($foreignResponse->status())->toBe(403)->and($missing->getContent())->toBe($foreignResponse->getContent());
    });
});

describe('configuración', function (): void {
    it('el servidor solo acepta conexiones del sitio de la aplicación y no admite eventos directos del navegador', function (): void {
        $app = config('reverb.apps.apps.0');

        expect($app['allowed_origins'])->not->toContain('*')->and($app['allowed_origins'])->toHaveCount(1)
            ->and($app['accept_client_events_from'])->toBe('none')
            ->and($app['rate_limiting']['enabled'])->toBeTrue()
            ->and($app['max_message_size'])->toBeLessThanOrEqual(10000)
            ->and($app['max_connections'])->toBeInt();
    });

    it('nginx solo expone la conexión del navegador y compose no publica el puerto de Reverb', function (): void {
        $nginx = (string) file_get_contents(base_path('docker/nginx/default.conf'));
        $compose = (string) file_get_contents(base_path('compose.yaml'));
        preg_match('/\n  reverb:\n(.*?)(?=\n  \S|\nvolumes:)/s', $compose, $block);

        expect($nginx)->toContain('location /app/')->not->toContain('location /apps')
            ->and($block[1] ?? '')->not->toBeEmpty()->not->toContain('ports:');
    });

    it('la cola no duerme: el trabajador espera en Redis y no entre consultas, para que los avisos lleguen en menos de 1 segundo', function (): void {
        $compose = (string) file_get_contents(base_path('compose.yaml'));
        preg_match('/\n  queue:\n(.*?)(?=\n  \S|\nvolumes:)/s', $compose, $block);

        expect(config('queue.connections.redis.block_for'))->toBeInt()->toBeGreaterThan(0)
            ->and($block[1] ?? '')->toContain('queue:work')->toContain('--sleep=0');
    });

    it('la política de contenido permite el WebSocket del mismo sitio', function (): void {
        $http = $this->get('http://tikaro.test:8080/login')->headers->get('Content-Security-Policy');
        $https = $this->get('https://tikaro.test/login')->headers->get('Content-Security-Policy');

        expect($http)->toContain("connect-src 'self' ws://tikaro.test:8080;")->and($https)->toContain("connect-src 'self' wss://tikaro.test;");
    });

    it('el ejemplo de entorno trae Reverb sin valores reales', function (): void {
        $example = (string) file_get_contents(base_path('.env.example'));

        expect($example)->toContain('BROADCAST_CONNECTION=reverb')->toContain('REVERB_APP_KEY=CHANGE_ME')->toContain('REVERB_APP_SECRET=CHANGE_ME');
    });
});
