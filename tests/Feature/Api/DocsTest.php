<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Api\ApiPermission;
use App\Api\OpenApiSpec;
use App\Api\Presenters;
use App\Enums\Role;
use App\Http\Requests\Api\StoreCustomerRequest;
use App\Http\Requests\Api\StoreMessageRequest;
use App\Http\Requests\Api\StoreTicketRequest;
use App\Http\Requests\Api\UpdateTicketRequest;
use App\Models\Customer;
use App\Models\Ticket;
use App\Tenancy\TenantContext;
use App\Tickets\Channel;
use App\Tickets\TicketPriority;
use App\Tickets\TicketStatus;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

/** @return array<string, array{method: string, permission: string|null}> Las rutas reales de la API: «GET /tickets/{ticket}» => permiso. */
function realApiRoutes(): array
{
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        /** @var LaravelRoute $route */
        if (! str_starts_with($route->uri(), 'api/v1/') || $route->uri() === 'api/v1/openapi.json') {
            continue;
        }

        $permission = collect($route->gatherMiddleware())->first(fn (mixed $m): bool => is_string($m) && str_starts_with($m, 'api.can:'));

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $routes[$method.' '.substr($route->uri(), strlen('api/v1'))] = ['method' => $method, 'permission' => is_string($permission) ? substr($permission, strlen('api.can:')) : null];
        }
    }

    ksort($routes);

    return $routes;
}

/** @return array<string, array{method: string, permission: string}> */
function documentedRoutes(): array
{
    $documented = [];

    foreach (OpenApiSpec::build()['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            $documented[strtoupper($method).' '.$path] = ['method' => strtoupper($method), 'permission' => $operation['x-permission']];
        }
    }

    ksort($documented);

    return $documented;
}

describe('la especificación se publica', function (): void {
    it('se entrega como JSON sin llave, con la forma de OpenAPI 3.1 y la dirección de esta instalación', function (): void {
        $response = $this->getJson('/api/v1/openapi.json')->assertOk();

        expect($response->json('openapi'))->toBe('3.1.0')
            ->and($response->json('servers.0.url'))->toBe(url('/api/v1'))
            ->and($response->json('security'))->toBe([['bearerAuth' => []]])
            ->and($response->json('components.securitySchemes.bearerAuth.scheme'))->toBe('bearer')
            ->and($response->headers->get('Cache-Control'))->toContain('public');
        expect($response->getContent())->not->toContain('tk_1_')->not->toContain('password');
    });

    it('tiene un tope de peticiones por dirección', function (): void {
        foreach (range(1, 60) as $_) {
            $this->getJson('/api/v1/openapi.json');
        }

        $this->getJson('/api/v1/openapi.json')->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    });
});

describe('la documentación coincide con el comportamiento', function (): void {
    it('cada ruta real está documentada y cada ruta documentada existe, con el mismo método', function (): void {
        $real = realApiRoutes();
        $documented = documentedRoutes();

        expect(array_keys($real))->toBe(array_keys($documented));
    });

    it('el permiso documentado de cada ruta es el que exige el servidor', function (): void {
        $real = realApiRoutes();

        foreach (documentedRoutes() as $key => $route) {
            expect($real[$key]['permission'])->toBe($route['permission'], $key);
        }
    });

    it('cada petición documentada acepta exactamente los campos que valida el servidor', function (): void {
        $schemas = OpenApiSpec::build()['components']['schemas'];
        $pairs = [
            'TicketCreate' => StoreTicketRequest::class,
            'TicketUpdate' => UpdateTicketRequest::class,
            'MessageCreate' => StoreMessageRequest::class,
            'CustomerCreate' => StoreCustomerRequest::class,
        ];

        foreach ($pairs as $schema => $request) {
            $rules = array_keys((new $request)->rules());
            $properties = array_keys($schemas[$schema]['properties']);

            expect($properties)->toEqualCanonicalizing($rules, $schema);
        }
    });

    it('los campos obligatorios documentados son los que el servidor exige', function (): void {
        $schemas = OpenApiSpec::build()['components']['schemas'];

        foreach (['TicketCreate' => StoreTicketRequest::class, 'MessageCreate' => StoreMessageRequest::class, 'CustomerCreate' => StoreCustomerRequest::class] as $schema => $request) {
            foreach ($schemas[$schema]['required'] as $field) {
                $rule = (new $request)->rules()[$field];
                expect(in_array('required', $rule, true))->toBeTrue("{$schema}.{$field}");
            }
        }
    });

    it('cada recurso documentado tiene exactamente los campos que entrega la API', function (): void {
        $schemas = OpenApiSpec::build()['components']['schemas'];
        $tenant = widgetTenant();
        $ticket = makeTicket($tenant);
        $message = app(TenantContext::class)->run($tenant, fn () => $ticket->messages()->firstOrFail());
        $customer = app(TenantContext::class)->run($tenant, fn () => Customer::query()->findOrFail($ticket->customer_id));
        $agent = ticketStaff($tenant, Role::Agent);

        expect(array_keys($schemas['Ticket']['properties']))->toBe(array_keys(Presenters::ticket(app(TenantContext::class)->run($tenant, fn () => Ticket::query()->findOrFail($ticket->id)))))
            ->and(array_keys($schemas['Message']['properties']))->toBe(array_keys(Presenters::message($message)))
            ->and(array_keys($schemas['Customer']['properties']))->toBe(array_keys(Presenters::customer($customer)))
            ->and(array_keys($schemas['Agent']['properties']))->toBe(array_keys(Presenters::agent($agent)));
    });

    it('los valores permitidos documentados son los del sistema', function (): void {
        $ticket = OpenApiSpec::build()['components']['schemas']['Ticket']['properties'];

        expect($ticket['status']['enum'])->toBe(array_map(fn (TicketStatus $s): string => $s->value, TicketStatus::cases()))
            ->and($ticket['priority']['enum'])->toBe(array_map(fn (TicketPriority $p): string => $p->value, TicketPriority::cases()))
            ->and($ticket['channel']['enum'])->toBe(array_map(fn (Channel $c): string => $c->value, Channel::cases()));
    });

    it('los códigos de error documentados son los que la API puede devolver', function (): void {
        $documented = OpenApiSpec::build()['components']['schemas']['Error']['properties']['error']['properties']['code']['enum'];
        $produced = [...array_keys(trans('api.errors')), 'plan_limit'];
        $produced = array_values(array_diff($produced, ['author_invalid']));

        expect($documented)->toEqualCanonicalizing($produced);
    });

    it('los ejemplos de la documentación son peticiones válidas', function (): void {
        $tenant = widgetTenant();
        $key = apiKeyFor($tenant);
        $schemas = OpenApiSpec::build()['components']['schemas'];

        $ticket = api($this, 'POST', '/tickets', $key, $schemas['TicketCreate']['example'])->assertCreated()->json('data');
        api($this, 'POST', "/tickets/{$ticket['id']}/messages", $key, $schemas['MessageCreate']['example'])->assertCreated();
        api($this, 'POST', '/customers', $key, ['name' => 'Otra Persona', 'email' => 'otra@cliente.com'] + $schemas['CustomerCreate']['example'])->assertStatus(201);
        $update = ['status' => $schemas['TicketUpdate']['example']['status']];
        api($this, 'PATCH', "/tickets/{$ticket['id']}", $key, $update)->assertOk();
    });

    it('los ejemplos de los recursos tienen los mismos campos que el esquema', function (): void {
        foreach (['Ticket', 'Message', 'Customer', 'Agent'] as $name) {
            $schema = OpenApiSpec::build()['components']['schemas'][$name];

            expect(array_keys($schema['example']))->toBe(array_keys($schema['properties']), $name);
        }
    });

    it('los códigos de estado documentados de una operación son los que da de verdad', function (): void {
        $tenant = widgetTenant();
        $admin = apiKeyFor($tenant);
        $read = apiKeyFor($tenant, ApiPermission::Read);
        $spec = OpenApiSpec::build()['paths'];

        expect(array_map('strval', array_keys($spec['/tickets']['post']['responses'])))->toContain('201', '401', '403', '422', '429');
        expect(api($this, 'POST', '/tickets', null, newApiTicket())->status())->toBe(401);
        expect(api($this, 'POST', '/tickets', $read, newApiTicket())->status())->toBe(403);
        expect(api($this, 'POST', '/tickets', $admin, ['subject' => ''])->status())->toBe(422);
        expect(array_map('strval', array_keys($spec['/tickets/{ticket}']['get']['responses'])))->toContain('404')->not->toContain('403')->not->toContain('422');
        expect(api($this, 'GET', '/tickets/99999999', $admin)->status())->toBe(404);
    });
});

describe('la pantalla de documentación', function (): void {
    it('el administrador la ve con la especificación y la dirección base', function (): void {
        $tenant = widgetTenant();

        $props = $this->actingAs(widgetAdmin($tenant))->get('/settings/api-docs')->assertOk()->viewData('page')['props'];

        expect($props['spec']['openapi'])->toBe('3.1.0')->and($props['base'])->toBe(url('/api/v1'))->and($props['copy']['title'])->toBe('Documentación de la API');
        expect(preg_match('/tk_\d+_[A-Za-z0-9]{40}/', (string) json_encode($props)))->toBe(0);
    });

    it('agentes y observadores no la abren', function (): void {
        $tenant = widgetTenant();

        foreach ([Role::Agent, Role::Observer] as $role) {
            $this->actingAs(ticketStaff($tenant, $role))->get('/settings/api-docs')->assertForbidden();
        }
        auth()->logout();
        $this->get('/settings/api-docs')->assertRedirect('/login');
    });
});
