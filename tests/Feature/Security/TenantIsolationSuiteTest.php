<?php

declare(strict_types=1);

require_once __DIR__.'/../Tickets/Helpers.php';
require_once __DIR__.'/../Api/Helpers.php';

use App\Api\ApiPermission;
use App\Enums\Role;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Tenant;
use App\Models\TicketView;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\Support\TenantFootprint;

/**
 * Suite consolidada de aislamiento entre empresas (RS-01, RF-31 y HU-13). No depende de una lista escrita a mano: recorre
 * todas las rutas de la aplicación que reciben un recurso de empresa y comprueba que otra empresa, con cualquier rol,
 * recibe 404. Una ruta nueva con un recurso de empresa entra sola a la prueba; un parámetro nuevo que no se pueda resolver
 * hace fallar la prueba hasta que alguien lo revise.
 */
beforeEach(function (): void {
    useTemporaryAttachmentDisk();
});

/** Parámetros de ruta que no son un recurso de empresa y por qué. */
const ISOLATION_PLAIN_PARAMETERS = [
    'token' => 'credencial de un solo uso (invitación, encuesta, recuperación): se busca por su huella y su empresa sale del propio token',
    'id' => 'verificación de correo: enlace firmado de la propia cuenta',
    'hash' => 'verificación de correo: enlace firmado de la propia cuenta',
    'handle' => 'huella de una sesión de la propia cuenta: solo se lista y se cierra lo de quien pide',
    'view' => 'vista guardada: se resuelve a mano dentro de la empresa; esta suite la cubre aparte',
    'tenant' => 'panel de la plataforma: la empresa es la raíz, no un recurso dentro de otra, y solo el personal de la plataforma llega a la ruta',
    'plan' => 'panel de la plataforma: el catálogo de planes es global (no pertenece a ninguna empresa) y solo el personal de la plataforma llega a la ruta',
];

/**
 * Las rutas con recursos de empresa: nombre y datos de cada una.
 *
 * @return list<array{route: LaravelRoute, params: array<string, class-string<Model>>, api: bool}>
 */
function isolationRoutes(): array
{
    $found = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $params = [];

        foreach ($route->signatureParameters(UrlRoutable::class) as $parameter) {
            $class = $parameter->getType()?->getName();

            if (is_string($class) && is_subclass_of($class, Model::class) && (in_array(BelongsToTenant::class, class_uses_recursive($class), true) || $class === User::class)) {
                $params[$parameter->getName()] = $class;
            }
        }

        if ($route->uri() === 'tickets/views/{view}') {
            $params['view'] = TicketView::class;
        }

        if ($params !== []) {
            $found[] = ['route' => $route, 'params' => $params, 'api' => str_starts_with($route->uri(), 'api/')];
        }
    }

    return $found;
}

/**
 * Dirección de la ruta con los recursos de la empresa dueña, firmada si la ruta lo exige.
 *
 * @param  array<string, class-string<Model>>  $params
 */
function isolationUrl(LaravelRoute $route, array $params, Tenant $owner): string
{
    $values = [];

    foreach ($params as $name => $class) {
        $model = $class::query()->withoutGlobalScopes()->where('tenant_id', $owner->id)->when($class === User::class, fn ($query) => $query->where('role', Role::Agent->value))->first();
        expect($model)->not->toBeNull("La prueba no tiene un {$class} de la empresa dueña para {$route->uri()}.");
        $values[$name] = $model?->getRouteKey();
    }

    if (collect($route->gatherMiddleware())->contains(fn (mixed $middleware): bool => $middleware === 'signed')) {
        return URL::temporarySignedRoute((string) $route->getName(), now()->addMinutes(5), $values);
    }

    return url(rtrim((string) preg_replace_callback('/\{(\w+)\??\}/', fn (array $match): string => (string) ($values[$match[1]] ?? $match[1]), $route->uri()), '/'));
}

it('todo parámetro de ruta es un recurso de empresa que esta suite prueba o una credencial justificada', function (): void {
    $covered = collect(isolationRoutes())->flatMap(fn (array $item): array => array_keys($item['params']))->all();
    $unexplained = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        foreach ($route->parameterNames() as $name) {
            if (! in_array($name, $covered, true) && ! array_key_exists($name, ISOLATION_PLAIN_PARAMETERS)) {
                $unexplained[] = "{$route->uri()} → {$name}";
            }
        }
    }

    expect($unexplained)->toBe([])->and(isolationRoutes())->not->toBeEmpty();
});

describe('el panel: otra empresa recibe 404 con cualquier rol', function (): void {
    it('en cada ruta con un recurso de empresa', function (): void {
        $owner = ticketTenant();
        $other = ticketTenant();
        TenantFootprint::seed($owner);
        $intruders = [Role::Admin, Role::Agent, Role::Observer];
        $routes = array_filter(isolationRoutes(), fn (array $item): bool => ! $item['api']);
        $failures = [];
        $requests = 0;

        foreach ($routes as $item) {
            $url = isolationUrl($item['route'], $item['params'], $owner);

            foreach (array_diff($item['route']->methods(), ['HEAD']) as $method) {
                foreach ($intruders as $role) {
                    $status = $this->actingAs(ticketStaff($other, $role))->call($method, $url)->getStatusCode();
                    $requests++;

                    if ($status !== 404) {
                        $failures[] = "{$role->value} de otra empresa: {$method} {$item['route']->uri()} → {$status}";
                    }
                }
            }
        }

        expect($failures)->toBe([])->and($requests)->toBeGreaterThanOrEqual(90);
    });

    it('la dueña sí llega al recurso (la prueba anterior no pasa por haber armado mal la dirección)', function (): void {
        $owner = ticketTenant();
        $seed = TenantFootprint::seed($owner);
        $admin = ticketStaff($owner, Role::Admin);
        $misses = [];

        foreach (array_filter(isolationRoutes(), fn (array $item): bool => ! $item['api'] && in_array('GET', $item['route']->methods(), true)) as $item) {
            $status = $this->actingAs($admin)->get(isolationUrl($item['route'], $item['params'], $owner))->getStatusCode();

            // Un adjunto «por revisar» o un recurso sin pantalla propia puede responder distinto de 200, pero nunca 404.
            if ($status === 404) {
                $misses[] = $item['route']->uri();
            }
        }

        expect($misses)->toBe([])->and($seed['ticket']->id)->toBeInt();
    });
});

describe('la API: la llave de otra empresa recibe 404', function (): void {
    it('en cada ruta de /api/v1 con un recurso de empresa', function (): void {
        $owner = ticketTenant();
        $other = ticketTenant();
        TenantFootprint::seed($owner);
        $key = apiKeyFor($other, ApiPermission::Admin);
        $failures = [];
        $count = 0;

        foreach (array_filter(isolationRoutes(), fn (array $item): bool => $item['api']) as $item) {
            $url = isolationUrl($item['route'], $item['params'], $owner);

            foreach (array_diff($item['route']->methods(), ['HEAD']) as $method) {
                $status = $this->json($method, $url, [], ['Authorization' => 'Bearer '.$key])->getStatusCode();
                $count++;

                if ($status !== 404) {
                    $failures[] = "{$method} {$item['route']->uri()} → {$status}";
                }
            }
        }

        expect($failures)->toBe([])->and($count)->toBeGreaterThanOrEqual(5);
    });
});

describe('el tiempo real: los canales de otra empresa se rechazan', function (): void {
    beforeEach(function (): void {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'llave-de-prueba',
            'broadcasting.connections.reverb.secret' => 'secreto-de-prueba',
            'broadcasting.connections.reverb.app_id' => '1',
        ]);
        require base_path('routes/channels.php');
    });

    it('cada canal registrado tiene aquí su caso, y la otra empresa recibe 403 en todos', function (): void {
        $owner = ticketTenant();
        $other = ticketTenant();
        $seed = TenantFootprint::seed($owner);
        $samples = [
            'tenant.{tenantId}.inbox' => "tenant.{$owner->id}.inbox",
            'tenant.{tenantId}.tickets.{ticketId}' => "tenant.{$owner->id}.tickets.{$seed['ticket']->id}",
            'tenant.{tenantId}.knowledge' => "tenant.{$owner->id}.knowledge",
        ];

        $registered = collect(app(BroadcastManager::class)->connection()->getChannels())->keys()->map(fn (mixed $pattern): string => (string) $pattern)->all();
        expect(array_values(array_diff($registered, array_keys($samples))))->toBe([], 'Hay un canal nuevo sin caso de aislamiento.');

        foreach ($samples as $channel) {
            foreach ([Role::Admin, Role::Agent, Role::Observer] as $role) {
                $this->actingAs(ticketStaff($other, $role))
                    ->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => "private-{$channel}"])
                    ->assertForbidden();
            }

            // Y la dueña, con un rol que puede ver, sí entra: el rechazo anterior no es un error de armado.
            $this->actingAs(ticketStaff($owner, Role::Admin))
                ->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => "private-{$channel}"])
                ->assertOk();
        }
    });
});

describe('lo que viaja en el cuerpo de la petición', function (): void {
    it('un identificador de otra empresa en un cuerpo se rechaza como si no existiera', function (): void {
        $owner = ticketTenant();
        $other = ticketTenant();
        $seed = TenantFootprint::seed($owner);
        $intruder = ticketStaff($other, Role::Admin);
        $customerId = $seed['ticket']->customer_id;

        // Crear un ticket para un cliente de otra empresa y asignarlo a un agente de otra empresa.
        $this->actingAs($intruder)->post('/tickets', ['subject' => 'Intruso', 'description' => 'Texto', 'priority' => 'normal', 'customer_id' => $customerId])->assertSessionHasErrors();

        $own = makeTicket($other);
        $this->actingAs($intruder)->patch("/tickets/{$own->id}", ['assigned_to' => $seed['agent']->id])->assertSessionHasErrors();
    });
});
