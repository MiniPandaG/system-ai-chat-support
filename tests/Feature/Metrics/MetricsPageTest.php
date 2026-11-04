<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-04 15:00:00 UTC');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function metricsAdmin(Tenant $tenant): User
{
    return ticketStaff($tenant, Role::Admin);
}

/** @return array<string, mixed> */
function metricsProps(TestResponse $response): array
{
    return $response->viewData('page')['props'];
}

describe('quién puede verla', function (): void {
    it('un administrador la ve', function (): void {
        $tenant = ticketTenant();

        $page = test()->actingAs(metricsAdmin($tenant))->get('/metrics')->assertOk()->viewData('page');

        expect($page['component'])->toBe('metrics/Index')->and($page['props']['copy']['title'])->toBe('Métricas');
    });

    it('un agente, un observador, el personal de la plataforma y quien no inició sesión no', function (): void {
        $tenant = ticketTenant();

        $this->actingAs(ticketStaff($tenant, Role::Agent))->get('/metrics')->assertForbidden();
        $this->actingAs(ticketStaff($tenant, Role::Observer))->get('/metrics')->assertForbidden();
        // El personal de la plataforma no es de ninguna empresa: antes de llegar a la política lo detiene el 2FA obligatorio.
        $platform = $this->actingAs(User::factory()->create(['tenant_id' => null, 'is_platform_admin' => true, 'role' => null]))->get('/metrics');
        expect($platform->getStatusCode())->toBeIn([302, 403]);
        auth()->logout();
        $this->get('/metrics')->assertRedirect();
    });

    it('un administrador desactivado no entra', function (): void {
        $tenant = ticketTenant();
        $admin = metricsAdmin($tenant);
        $admin->forceFill(['disabled_at' => now()])->save();

        expect($this->actingAs($admin)->get('/metrics')->getStatusCode())->toBeIn([302, 403]);
    });

    it('la entrada «Métricas» del menú es solo del administrador', function (): void {
        $tenant = ticketTenant();

        $admin = $this->actingAs(metricsAdmin($tenant))->get('/metrics')->viewData('page');
        $agent = $this->actingAs(ticketStaff($tenant, Role::Agent))->get('/tickets')->viewData('page');

        expect(data_get($admin['props'], 'auth.can.metrics'))->toBeTrue()->and(data_get($agent['props'], 'auth.can.metrics'))->toBeFalse();
    });
});

describe('qué muestra', function (): void {
    it('por defecto los últimos 30 días, con las tarjetas, la satisfacción, el costo y la tendencia', function (): void {
        $tenant = ticketTenant();
        metricTicket($tenant, ['created' => '2026-11-03 10:00:00', 'first_response' => '2026-11-03 10:05:00', 'resolved' => '2026-11-03 12:00:00']);
        metricCsat($tenant, metricTicket($tenant), 5, 'Excelente');

        $props = metricsProps($this->actingAs(metricsAdmin($tenant))->get('/metrics')->assertOk());

        expect($props['filters'])->toBe(['range' => '30d', 'from' => '2026-10-06', 'to' => '2026-11-04', 'score' => null])
            ->and($props['range_error'])->toBeNull()
            ->and(array_keys($props['report']['cards']))->toBe(['received', 'resolved', 'first_response', 'resolution', 'csat', 'ai_resolved'])
            ->and($props['report']['cards']['received']['value'])->toBe(2)->and($props['report']['cards']['csat']['value'])->toBe(5.0)
            ->and($props['report']['trend'])->toHaveCount(30)->and($props['report']['ai'])->toHaveKey('spent');
    });

    it('cada preajuste y las fechas propias cambian el rango', function (string $query, string $from, string $to): void {
        $tenant = ticketTenant();

        $props = metricsProps($this->actingAs(metricsAdmin($tenant))->get('/metrics?'.$query)->assertOk());

        expect($props['filters']['from'])->toBe($from)->and($props['filters']['to'])->toBe($to)->and($props['range_error'])->toBeNull();
    })->with([
        'hoy' => ['range=today', '2026-11-04', '2026-11-04'],
        '7 días' => ['range=7d', '2026-10-29', '2026-11-04'],
        'personalizado' => ['range=custom&from=2026-10-01&to=2026-10-31', '2026-10-01', '2026-10-31'],
    ]);

    it('un rango que no vale no rompe la pantalla: avisa y muestra los 30 días', function (string $query): void {
        $tenant = ticketTenant();

        $props = metricsProps($this->actingAs(metricsAdmin($tenant))->get('/metrics?'.$query)->assertOk());

        expect($props['range_error'])->toContain('Ese rango de fechas no vale')->and($props['filters'])->toMatchArray(['range' => '30d', 'from' => '2026-10-06', 'to' => '2026-11-04']);
    })->with([
        'rango desconocido' => ['range=siempre'],
        'sin fechas' => ['range=custom'],
        'al revés' => ['range=custom&from=2026-11-03&to=2026-11-01'],
        'en el futuro' => ['range=custom&from=2026-11-01&to=2027-01-01'],
        'fecha que no existe' => ['range=custom&from=2026-02-30&to=2026-03-01'],
        'demasiado largo' => ['range=custom&from=2020-01-01&to=2026-11-04'],
        'con texto' => ['range=custom&from=ayer&to=hoy'],
    ]);

    it('un rango mandado como lista se ignora y se usan los 30 días sin alarmar', function (): void {
        $props = metricsProps($this->actingAs(metricsAdmin(ticketTenant()))->get('/metrics?range[]=7d&from[]=x'));

        expect($props['filters']['range'])->toBe('30d')->and($props['range_error'])->toBeNull();
    });

    it('los comentarios se filtran por puntaje y un puntaje que no vale se ignora', function (): void {
        $tenant = ticketTenant();
        metricCsat($tenant, metricTicket($tenant), 5, 'Genial');
        metricCsat($tenant, metricTicket($tenant), 1, 'Pésimo');
        $admin = metricsAdmin($tenant);

        $five = metricsProps($this->actingAs($admin)->get('/metrics?score=5'))['comments']['data'];
        $all = metricsProps($this->actingAs($admin)->get('/metrics?score=9'))['comments']['data'];
        $text = metricsProps($this->actingAs($admin)->get('/metrics?score=abc'))['comments']['data'];

        expect(array_column($five, 'comment'))->toBe(['Genial'])->and($all)->toHaveCount(2)->and($text)->toHaveCount(2);
    });

    it('cada comentario trae su ticket enlazable y la hora en la zona de la plataforma, sin nombre ni correo del cliente', function (): void {
        $tenant = ticketTenant();
        $ticket = metricTicket($tenant);
        metricCsat($tenant, $ticket, 4, 'Bien', '2026-11-04 15:30:00');

        $comment = metricsProps($this->actingAs(metricsAdmin($tenant))->get('/metrics'))['comments']['data'][0];

        expect($comment['ticket'])->toBe(['id' => $ticket->id, 'number' => $ticket->number, 'subject' => $ticket->subject])->and($comment['answered_label'])->toContain('10:30')
            ->and(array_keys($comment))->toBe(['id', 'score', 'comment', 'answered_label', 'ticket']);
    });

    it('un comentario con HTML llega tal cual como texto para que la pantalla lo escape', function (): void {
        $tenant = ticketTenant();
        metricCsat($tenant, metricTicket($tenant), 2, '<script>alert(1)</script> <b>mal</b>');

        $comment = metricsProps($this->actingAs(metricsAdmin($tenant))->get('/metrics'))['comments']['data'][0]['comment'];

        expect($comment)->toBe('<script>alert(1)</script> <b>mal</b>');
    });

    it('los comentarios van de 15 en 15 con enlace a la siguiente página que conserva el filtro', function (): void {
        $tenant = ticketTenant();
        foreach (range(1, 17) as $n) {
            metricCsat($tenant, metricTicket($tenant), 5, "Comentario {$n}");
        }

        $props = metricsProps($this->actingAs(metricsAdmin($tenant))->get('/metrics?range=7d&score=5'));

        expect($props['comments']['data'])->toHaveCount(15)->and($props['comments']['next_url'])->toContain('page=2')->toContain('score=5')->toContain('range=7d');
    });
});

describe('aislamiento', function (): void {
    it('un administrador solo ve cifras y comentarios de su empresa', function (): void {
        $mine = ticketTenant();
        $other = ticketTenant();
        metricTicket($mine);
        foreach (range(1, 3) as $_) {
            $ticket = metricTicket($other, ['created' => '2026-11-03 10:00:00', 'first_response' => '2026-11-03 10:01:00', 'resolved' => '2026-11-03 11:00:00']);
            metricCsat($other, $ticket, 1, 'Comentario de la otra empresa');
        }

        $props = metricsProps($this->actingAs(metricsAdmin($mine))->get('/metrics?range=7d'));

        expect($props['report']['cards']['received']['value'])->toBe(1)->and($props['report']['cards']['resolved']['value'])->toBe(0)->and($props['report']['cards']['csat']['count'])->toBe(0)
            ->and($props['comments']['data'])->toBe([])->and($props['report']['open_now'])->toBe(1)
            ->and(json_encode($props))->not->toContain('otra empresa');
    });

    it('lo que el cliente intente mandar para ver otra empresa se ignora', function (): void {
        $mine = ticketTenant();
        $other = ticketTenant();
        metricCsat($other, metricTicket($other), 1, 'Ajeno');

        $props = metricsProps($this->actingAs(metricsAdmin($mine))->get('/metrics?tenant_id='.$other->id.'&tenant='.$other->id));

        expect($props['comments']['data'])->toBe([]);
    });
});

describe('límites', function (): void {
    it('tiene un tope de peticiones por persona', function (): void {
        $tenant = ticketTenant();
        $admin = metricsAdmin($tenant);
        RateLimiter::clear('metrics');

        $last = 200;
        foreach (range(1, 65) as $_) {
            $last = $this->actingAs($admin)->get('/metrics?range=today')->getStatusCode();
            if ($last === 429) {
                break;
            }
        }

        expect($last)->toBe(429);
    });
});
