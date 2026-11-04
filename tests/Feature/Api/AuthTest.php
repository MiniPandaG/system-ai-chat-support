<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Actions\CreateTicket;
use App\Api\ApiKeys;
use App\Api\ApiPermission;
use App\Models\Ticket;
use App\Tenancy\TenantContext;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;

describe('quién llama', function (): void {
    it('sin llave, con mala forma, desconocida, revocada o caducada recibe el mismo 401', function (): void {
        $tenant = widgetTenant();
        $revoked = apiKeyFor($tenant);
        $expired = apiKeyFor($tenant, expiresInDays: 30);
        $admin = widgetAdmin($tenant);
        app(TenantContext::class)->run($tenant, fn () => app(ApiKeys::class)->revoke($admin, apiKeyRow($tenant, $revoked)));
        $this->travel(31)->days();

        $bodies = [];
        foreach ([null, '', 'basura', 'gk_1_corta', "gk_{$tenant->id}_".str_repeat('a', 40), 'gk_99999999_'.str_repeat('b', 40), $revoked, $expired] as $key) {
            $response = api($this, 'GET', '/tickets', $key)->assertUnauthorized();
            $bodies[] = $response->json();
            expect($response->headers->get('WWW-Authenticate'))->toBe('Bearer');
        }

        expect(array_unique(array_map('json_encode', $bodies)))->toHaveCount(1)
            ->and($bodies[0]['error']['code'])->toBe('unauthenticated');
    });

    it('una llave con la empresa cambiada no sirve en la otra', function (): void {
        $mine = widgetTenant();
        $theirs = widgetTenant();
        $key = apiKeyFor($mine);
        $forged = 'gk_'.$theirs->id.substr($key, strlen('gk_'.$mine->id));

        api($this, 'GET', '/tickets', $forged)->assertUnauthorized();
    });

    it('la llave en la URL o en el cuerpo no cuenta: solo la cabecera Authorization', function (): void {
        $tenant = widgetTenant();
        $key = apiKeyFor($tenant);

        $this->getJson('/api/v1/tickets?api_key='.$key)->assertUnauthorized();
        $this->postJson('/api/v1/tickets', ['api_key' => $key, ...newApiTicket()])->assertUnauthorized();
    });

    it('anota el último uso, como mucho una vez por minuto', function (): void {
        $tenant = widgetTenant();
        $key = apiKeyFor($tenant);
        expect(apiKeyRow($tenant, $key)->last_used_at)->toBeNull();

        api($this, 'GET', '/tickets', $key)->assertOk();
        $first = apiKeyRow($tenant, $key)->last_used_at;
        $this->travel(30)->seconds();
        api($this, 'GET', '/tickets', $key)->assertOk();
        expect(apiKeyRow($tenant, $key)->last_used_at?->equalTo($first))->toBeTrue();

        $this->travel(40)->seconds();
        api($this, 'GET', '/tickets', $key)->assertOk();
        expect(apiKeyRow($tenant, $key)->last_used_at?->isAfter($first))->toBeTrue();
    });

    it('revocar corta la llave en la siguiente petición', function (): void {
        $tenant = widgetTenant();
        $key = apiKeyFor($tenant);
        api($this, 'GET', '/tickets', $key)->assertOk();

        $this->actingAs(widgetAdmin($tenant))->delete('/settings/api-keys/'.apiKeyRow($tenant, $key)->id)->assertRedirect();
        auth()->logout();

        api($this, 'GET', '/tickets', $key)->assertUnauthorized();
    });

    it('no usa cookies ni sesión', function (): void {
        $tenant = widgetTenant();
        $key = apiKeyFor($tenant);

        $response = api($this, 'GET', '/tickets', $key)->assertOk();

        expect($response->headers->getCookies())->toBe([]);
    });

    it('quien acumula intentos fallidos desde una dirección recibe 429 con el tiempo de espera, y una llave buena de otra no se ve afectada', function (): void {
        config(['api.limits.failed_auth_per_ip_per_minute' => 3]);
        $tenant = widgetTenant();
        $key = apiKeyFor($tenant);

        foreach (range(1, 3) as $_) {
            api($this, 'GET', '/tickets', 'gk_1_'.str_repeat('z', 40))->assertUnauthorized();
        }

        $blocked = api($this, 'GET', '/tickets', 'gk_1_'.str_repeat('z', 40))->assertStatus(429);
        expect($blocked->json('error.code'))->toBe('rate_limited')->and((int) $blocked->headers->get('Retry-After'))->toBeGreaterThan(0);
        api($this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77']), 'GET', '/tickets', $key)->assertOk();
        $this->travel(61)->seconds();
        api($this, 'GET', '/tickets', $key)->assertOk();
    });

    it('la llave jamás aparece en la auditoría, en la base ni en las respuestas', function (): void {
        $tenant = widgetTenant();
        $key = apiKeyFor($tenant);

        $response = api($this, 'POST', '/tickets', $key, newApiTicket())->assertCreated();

        $secret = substr($key, strlen('gk_'.$tenant->id.'_'));
        expect($response->getContent())->not->toContain($secret)
            ->and(json_encode(DB::table('audit_logs')->get()))->not->toContain($secret)
            ->and(json_encode(DB::table('api_keys')->get()))->not->toContain($secret);
    });
});

describe('qué puede hacer cada llave', function (): void {
    it('cada nivel alcanza lo suyo y lo de los niveles de abajo, y nada más', function (): void {
        $tenant = widgetTenant();
        $ticket = makeTicket($tenant);
        $keys = [];
        foreach (ApiPermission::cases() as $level) {
            $keys[$level->value] = apiKeyFor($tenant, $level);
        }
        $matrix = [
            ['GET', '/tickets', [], [ApiPermission::Read, ApiPermission::Write, ApiPermission::Admin]],
            ['GET', "/tickets/{$ticket->id}", [], [ApiPermission::Read, ApiPermission::Write, ApiPermission::Admin]],
            ['GET', "/tickets/{$ticket->id}/messages", [], [ApiPermission::Read, ApiPermission::Write, ApiPermission::Admin]],
            ['GET', '/customers', [], [ApiPermission::Read, ApiPermission::Write, ApiPermission::Admin]],
            ['GET', '/agents', [], [ApiPermission::Read, ApiPermission::Write, ApiPermission::Admin]],
            ['POST', '/tickets', newApiTicket(), [ApiPermission::Write, ApiPermission::Admin]],
            ['POST', "/tickets/{$ticket->id}/messages", ['body' => 'Hola'], [ApiPermission::Write, ApiPermission::Admin]],
            ['POST', '/customers', ['name' => 'Otra Persona'], [ApiPermission::Write, ApiPermission::Admin]],
            ['PATCH', "/tickets/{$ticket->id}", ['priority' => 'high'], [ApiPermission::Admin]],
        ];

        foreach ($matrix as [$method, $uri, $data, $allowed]) {
            foreach (ApiPermission::cases() as $level) {
                $status = api($this, $method, $uri, $keys[$level->value], $data)->status();

                if (in_array($level, $allowed, true)) {
                    expect($status)->toBeIn([200, 201], "{$method} {$uri} con {$level->value}");
                } else {
                    expect($status)->toBe(403, "{$method} {$uri} con {$level->value}");
                }
            }
        }
    });

    it('el permiso se exige antes de resolver el ticket: una llave de lectura no averigua si existe', function (): void {
        $tenant = widgetTenant();
        $read = apiKeyFor($tenant, ApiPermission::Read);

        $existing = api($this, 'POST', '/tickets/'.makeTicket($tenant)->id.'/messages', $read, ['body' => 'x']);
        $missing = api($this, 'POST', '/tickets/99999999/messages', $read, ['body' => 'x']);

        expect($existing->status())->toBe(403)->and($missing->status())->toBe(403)->and($existing->json())->toBe($missing->json());
    });

    it('el 403 dice el código y no nombra la llave ni la empresa', function (): void {
        $tenant = widgetTenant();
        $read = apiKeyFor($tenant, ApiPermission::Read);

        $response = api($this, 'POST', '/tickets', $read, newApiTicket())->assertForbidden();

        expect($response->json())->toBe(['error' => ['code' => 'forbidden', 'message' => 'Esta llave no tiene permiso para esta acción.']]);
    });
});

describe('límites de peticiones', function (): void {
    it('una llave tiene un tope por minuto con 429, su código y cuánto esperar', function (): void {
        config(['api.limits.per_key_per_minute' => 3]);
        $tenant = widgetTenant();
        $key = apiKeyFor($tenant);

        foreach (range(1, 3) as $_) {
            api($this, 'GET', '/tickets', $key)->assertOk();
        }
        $blocked = api($this, 'GET', '/tickets', $key)->assertStatus(429);

        expect($blocked->json('error.code'))->toBe('rate_limited')->and((int) $blocked->headers->get('Retry-After'))->toBeGreaterThan(0);
        api($this, 'GET', '/tickets', apiKeyFor($tenant))->assertOk();
        $this->travel(61)->seconds();
        api($this, 'GET', '/tickets', $key)->assertOk();
    });

    it('una dirección tiene un tope por minuto entre todas sus llaves', function (): void {
        config(['api.limits.per_ip_per_minute' => 2, 'api.limits.per_key_per_minute' => 100]);
        $tenant = widgetTenant();

        api($this, 'GET', '/tickets', apiKeyFor($tenant))->assertOk();
        api($this, 'GET', '/tickets', apiKeyFor($tenant))->assertOk();
        api($this, 'GET', '/tickets', apiKeyFor($tenant))->assertStatus(429);
    });

    it('escribir tiene un tope más bajo por llave', function (): void {
        config(['api.limits.writes_per_key_per_minute' => 2]);
        $tenant = widgetTenant();
        $key = apiKeyFor($tenant);

        api($this, 'POST', '/customers', $key, ['name' => 'Uno'])->assertCreated();
        api($this, 'POST', '/customers', $key, ['name' => 'Dos'])->assertCreated();
        api($this, 'POST', '/customers', $key, ['name' => 'Tres'])->assertStatus(429);
        api($this, 'GET', '/customers', $key)->assertOk();
    });

    it('con el tope de tickets del plan alcanzado responde 422 con el mensaje del plan', function (): void {
        $tenant = widgetTenant('inicial');
        DB::table('plans')->where('slug', 'inicial')->update(['max_tickets_per_month' => 1]);
        $key = apiKeyFor($tenant);
        api($this, 'POST', '/tickets', $key, newApiTicket())->assertCreated();

        $blocked = api($this, 'POST', '/tickets', $key, newApiTicket(['customer_email' => 'otro@cliente.com']))->assertStatus(422);

        expect($blocked->json('error.code'))->toBe('plan_limit')->and($blocked->json('error.limit'))->toBe('tickets_per_month')->and($blocked->json('error.message'))->toContain('Inicial');
        expect(app(TenantContext::class)->run($tenant, fn () => Ticket::query()->count()))->toBe(1);
    });
});

describe('errores con formato propio', function (): void {
    it('una ruta que no existe, un método no permitido y un cuerpo roto responden con el formato de error de la API', function (): void {
        $tenant = widgetTenant();
        $key = apiKeyFor($tenant);

        $notFound = api($this, 'GET', '/no-existe', $key)->assertNotFound();
        $method = api($this, 'DELETE', '/tickets', $key)->assertStatus(405);

        expect($notFound->json('error.code'))->toBe('not_found')->and($method->json('error.code'))->toBe('method_not_allowed');
        $broken = $this->call('POST', '/api/v1/tickets', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$key, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{roto');
        expect($broken->status())->toBe(422)->and($broken->json('error.code'))->toBe('validation_failed');
    });

    it('un error del servidor es genérico y no filtra nada interno', function (): void {
        $tenant = widgetTenant();
        $key = apiKeyFor($tenant);
        $this->app->bind(CreateTicket::class, fn () => throw new RuntimeException('SELECT * FROM secretos /var/www/html/app/Interno.php'));

        $response = api($this, 'POST', '/tickets', $key, newApiTicket())->assertStatus(500);

        expect($response->json())->toBe(['error' => ['code' => 'server_error', 'message' => 'Ocurrió un error en el servidor. Inténtalo de nuevo más tarde.']])
            ->and($response->getContent())->not->toContain('secretos')->not->toContain('/var/www');
    });

    it('un ticket de otra empresa da 404 por cualquier camino', function (): void {
        $mine = widgetTenant();
        $theirs = widgetTenant();
        $key = apiKeyFor($mine);
        $theirTicket = makeTicket($theirs);

        api($this, 'GET', "/tickets/{$theirTicket->id}", $key)->assertNotFound();
        api($this, 'GET', "/tickets/{$theirTicket->id}/messages", $key)->assertNotFound();
        api($this, 'POST', "/tickets/{$theirTicket->id}/messages", $key, ['body' => 'Hola'])->assertNotFound();
        api($this, 'PATCH', "/tickets/{$theirTicket->id}", $key, ['priority' => 'urgent'])->assertNotFound();
        expect(widgetBodies($theirs, $theirTicket))->toHaveCount(1)->and(reloaded($theirTicket)->priority->value)->toBe('normal');
    });
});

it('no hay CORS abierto: ningún sitio ajeno puede llamar a la API desde el navegador, salvo los orígenes que se listen', function (): void {
    $tenant = widgetTenant();
    $key = apiKeyFor($tenant);

    $response = api($this, 'GET', '/tickets', $key, headers: ['Origin' => 'https://sitio-ajeno.example'])->assertOk();

    expect($response->headers->get('Access-Control-Allow-Origin'))->toBeNull();
});

it('toda ruta de la API exige llave (salvo ninguna)', function (): void {
    $unprotected = collect(Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->filter(fn (Route $route): bool => str_starts_with($route->uri(), 'api/v1') && $route->uri() !== 'api/v1/openapi.json')
        ->filter(fn (Route $route): bool => ! in_array('api.key', $route->gatherMiddleware(), true) || ! collect($route->gatherMiddleware())->contains(fn (mixed $m): bool => is_string($m) && str_starts_with($m, 'api.can:')))
        ->map(fn (Route $route): string => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    expect($unprotected)->toBe([]);
});
