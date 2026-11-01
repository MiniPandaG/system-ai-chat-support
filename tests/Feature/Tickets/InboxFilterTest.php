<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tickets\TicketPriority;
use App\Tickets\TicketStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

afterEach(function (): void {
    Carbon::setTestNow();
});

/** 35 tickets con una hora distinta cada uno: el más nuevo es el «Ticket 35». */
function manyTickets(int $count = 35): array
{
    $tenant = ticketTenant();
    foreach (range(1, $count) as $i) {
        titled($tenant, "Ticket {$i}", ['last_message_at' => Carbon::parse('2026-10-01 00:00:00')->addMinutes($i)]);
    }

    return [$tenant, ticketStaff($tenant)];
}

describe('filtros rápidos', function (): void {
    it('«Asignados a mí» muestra solo mis tickets pendientes', function (): void {
        $tenant = ticketTenant();
        $me = ticketStaff($tenant);
        $other = ticketStaff($tenant);
        titled($tenant, 'Mío abierto', ['assigned_to' => $me->id]);
        titled($tenant, 'Mío en espera', ['assigned_to' => $me->id, 'status' => TicketStatus::WaitingCustomer]);
        titled($tenant, 'Mío resuelto', ['assigned_to' => $me->id, 'status' => TicketStatus::Resolved]);
        titled($tenant, 'Mío cerrado', ['assigned_to' => $me->id, 'status' => TicketStatus::Closed]);
        titled($tenant, 'De otro', ['assigned_to' => $other->id]);
        titled($tenant, 'De nadie');

        $subjects = inboxSubjects($this->actingAs($me)->get('/tickets?scope=mine'));

        expect($subjects)->toEqualCanonicalizing(['Mío abierto', 'Mío en espera']);
    });

    it('«Sin asignar» muestra solo lo pendiente que nadie atiende', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        titled($tenant, 'Libre abierto');
        titled($tenant, 'Libre en espera', ['status' => TicketStatus::WaitingCustomer]);
        titled($tenant, 'Libre cerrado', ['status' => TicketStatus::Closed]);
        titled($tenant, 'Con dueño', ['assigned_to' => $agent->id]);

        expect(inboxSubjects($this->actingAs($agent)->get('/tickets?scope=unassigned')))->toEqualCanonicalizing(['Libre abierto', 'Libre en espera']);
    });

    it('si se elige un estado, el filtro rápido lo respeta en vez de limitarse a lo pendiente', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        titled($tenant, 'Libre abierto');
        titled($tenant, 'Libre cerrado', ['status' => TicketStatus::Closed]);

        expect(inboxSubjects($this->actingAs($agent)->get('/tickets?scope=unassigned&status[]=closed')))->toBe(['Libre cerrado']);
    });

    it('«Todos» muestra todo, también lo cerrado', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        titled($tenant, 'A');
        titled($tenant, 'B', ['status' => TicketStatus::Closed]);

        expect(inboxSubjects($this->actingAs($agent)->get('/tickets?scope=all')))->toEqualCanonicalizing(['A', 'B']);
    });
});

describe('filtros avanzados', function (): void {
    it('filtra por uno o varios estados', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        titled($tenant, 'Abierto');
        titled($tenant, 'En espera', ['status' => TicketStatus::WaitingCustomer]);
        titled($tenant, 'Resuelto', ['status' => TicketStatus::Resolved]);
        titled($tenant, 'Cerrado', ['status' => TicketStatus::Closed]);

        expect(inboxSubjects($this->actingAs($agent)->get('/tickets?status[]=resolved')))->toBe(['Resuelto'])
            ->and(inboxSubjects($this->actingAs($agent)->get('/tickets?status[]=open&status[]=closed')))->toEqualCanonicalizing(['Abierto', 'Cerrado']);
    });

    it('filtra por una o varias prioridades', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        titled($tenant, 'Baja', ['priority' => TicketPriority::Low]);
        titled($tenant, 'Normal');
        titled($tenant, 'Alta', ['priority' => TicketPriority::High]);
        titled($tenant, 'Urgente', ['priority' => TicketPriority::Urgent]);

        expect(inboxSubjects($this->actingAs($agent)->get('/tickets?priority[]=urgent')))->toBe(['Urgente'])
            ->and(inboxSubjects($this->actingAs($agent)->get('/tickets?priority[]=high&priority[]=low')))->toEqualCanonicalizing(['Alta', 'Baja']);
    });

    it('filtra por la persona que atiende', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $colleague = ticketStaff($tenant);
        titled($tenant, 'De ella', ['assigned_to' => $colleague->id]);
        titled($tenant, 'De otro', ['assigned_to' => $agent->id]);
        titled($tenant, 'De nadie');

        expect(inboxSubjects($this->actingAs($agent)->get("/tickets?agent={$colleague->id}")))->toBe(['De ella']);
    });

    it('filtra por etiqueta', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $tag = makeTag($tenant, ['name' => 'VIP']);
        $tagged = titled($tenant, 'Con etiqueta');
        titled($tenant, 'Sin etiqueta');
        app(TenantContext::class)->run($tenant, fn () => $tagged->tags()->attach($tag->id, ['tenant_id' => $tenant->id]));

        expect(inboxSubjects($this->actingAs($agent)->get("/tickets?tag={$tag->id}")))->toBe(['Con etiqueta']);
    });

    it('filtra por rango de fechas de creación, con los dos extremos incluidos', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        titled($tenant, 'Antes', ['created_at' => '2026-09-30 23:59:59']);
        titled($tenant, 'Primer día', ['created_at' => '2026-10-01 00:00:00']);
        titled($tenant, 'En medio', ['created_at' => '2026-10-15 12:00:00']);
        titled($tenant, 'Último día', ['created_at' => '2026-10-31 23:59:59']);
        titled($tenant, 'Después', ['created_at' => '2026-11-01 00:00:00']);

        expect(inboxSubjects($this->actingAs($agent)->get('/tickets?from=2026-10-01&to=2026-10-31')))->toEqualCanonicalizing(['Primer día', 'En medio', 'Último día'])
            ->and(inboxSubjects($this->actingAs($agent)->get('/tickets?from=2026-10-31')))->toEqualCanonicalizing(['Último día', 'Después'])
            ->and(inboxSubjects($this->actingAs($agent)->get('/tickets?to=2026-09-30')))->toBe(['Antes']);
    });

    it('los filtros se combinan: deben cumplirse todos a la vez', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        titled($tenant, 'Cumple todo', ['status' => TicketStatus::Open, 'priority' => TicketPriority::Urgent, 'assigned_to' => $agent->id]);
        titled($tenant, 'Falla estado', ['status' => TicketStatus::Closed, 'priority' => TicketPriority::Urgent, 'assigned_to' => $agent->id]);
        titled($tenant, 'Falla prioridad', ['status' => TicketStatus::Open, 'priority' => TicketPriority::Low, 'assigned_to' => $agent->id]);
        titled($tenant, 'Falla agente', ['status' => TicketStatus::Open, 'priority' => TicketPriority::Urgent]);

        $response = $this->actingAs($agent)->get("/tickets?status[]=open&priority[]=urgent&agent={$agent->id}");

        expect(inboxSubjects($response))->toBe(['Cumple todo'])->and(inboxProp($response, 'total'))->toBe(1);
    });

    it('sin coincidencias la lista llega vacía y el total es cero', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        titled($tenant, 'Algo');

        $response = $this->actingAs($agent)->get('/tickets?status[]=closed');

        expect(inboxRows($response))->toBe([])->and(inboxProp($response, 'total'))->toBe(0);
    });

    it('los filtros activos vuelven a la pantalla tal como se aplicaron', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);

        $response = $this->actingAs($agent)->get('/tickets?scope=mine&status[]=open&priority[]=high&sort=priority&from=2026-10-01');

        expect(inboxProp($response, 'filters'))->toBe(['scope' => 'mine', 'status' => ['open'], 'priority' => ['high'], 'from' => '2026-10-01', 'sort' => 'priority', 'saved' => null]);
    });
});

describe('valores no válidos', function (): void {
    it('rechazan en la pantalla lo que no es de la lista cerrada, sin tocar la consulta', function (string $query): void {
        $tenant = ticketTenant();
        titled($tenant, 'Algo');

        $this->actingAs(ticketStaff($tenant))->get("/tickets?{$query}")->assertSessionHasErrors();
    })->with([
        'estado inventado' => ['status[]=hackeado'],
        'prioridad inventada' => ['priority[]=critica'],
        'ámbito inventado' => ['scope=todos-los-clientes'],
        'orden inventado' => ['sort=id;drop table tickets'],
        'agente no numérico' => ['agent=abc'],
        'etiqueta negativa' => ['tag=-1'],
        'fecha mal escrita' => ['from=31/10/2026'],
        'estado como texto suelto' => ['status=open'],
        'búsqueda demasiado larga' => ['q='.'a'.'bcdefghij'.'abcdefghij'.'abcdefghij'.'abcdefghij'.'abcdefghij'.'abcdefghij'.'abcdefghij'.'abcdefghij'.'abcdefghij'.'abcdefghij'.'x'],
    ]);

    it('la búsqueda de tramos responde 422 con la misma validación', function (): void {
        $agent = ticketStaff(ticketTenant());

        $this->actingAs($agent)->getJson('/tickets/page?status[]=hackeado')->assertUnprocessable()->assertJsonValidationErrors('status.0');
        $this->actingAs($agent)->getJson('/tickets/page?sort=nope')->assertUnprocessable()->assertJsonValidationErrors('sort');
    });

    it('un texto de búsqueda en forma de lista no rompe nada', function (): void {
        $agent = ticketStaff(ticketTenant());

        $this->actingAs($agent)->get('/tickets?q[]=a')->assertSessionHasErrors('q');
    });
});

describe('orden', function (): void {
    it('«Más reciente» pone primero el último mensaje más nuevo', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        titled($tenant, 'Viejo', ['last_message_at' => '2026-10-01 10:00:00']);
        titled($tenant, 'Nuevo', ['last_message_at' => '2026-10-30 10:00:00']);
        titled($tenant, 'Medio', ['last_message_at' => '2026-10-15 10:00:00']);

        expect(inboxSubjects($this->actingAs($agent)->get('/tickets')))->toBe(['Nuevo', 'Medio', 'Viejo'])
            ->and(inboxSubjects($this->actingAs($agent)->get('/tickets?sort=recent')))->toBe(['Nuevo', 'Medio', 'Viejo']);
    });

    it('«Más antiguo» pone primero el ticket creado antes', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        titled($tenant, 'Creado tarde', ['created_at' => '2026-10-30 10:00:00', 'last_message_at' => '2026-10-31 10:00:00']);
        titled($tenant, 'Creado primero', ['created_at' => '2026-10-01 10:00:00', 'last_message_at' => '2026-10-02 10:00:00']);
        titled($tenant, 'Creado en medio', ['created_at' => '2026-10-15 10:00:00', 'last_message_at' => '2026-10-16 10:00:00']);

        expect(inboxSubjects($this->actingAs($agent)->get('/tickets?sort=oldest')))->toBe(['Creado primero', 'Creado en medio', 'Creado tarde']);
    });

    it('«Prioridad» pone primero lo más urgente y, dentro de cada prioridad, lo más reciente', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        titled($tenant, 'Baja reciente', ['priority' => TicketPriority::Low, 'last_message_at' => '2026-10-31 10:00:00']);
        titled($tenant, 'Urgente vieja', ['priority' => TicketPriority::Urgent, 'last_message_at' => '2026-10-01 10:00:00']);
        titled($tenant, 'Alta', ['priority' => TicketPriority::High, 'last_message_at' => '2026-10-20 10:00:00']);
        titled($tenant, 'Urgente nueva', ['priority' => TicketPriority::Urgent, 'last_message_at' => '2026-10-25 10:00:00']);
        titled($tenant, 'Normal', ['priority' => TicketPriority::Normal, 'last_message_at' => '2026-10-28 10:00:00']);

        expect(inboxSubjects($this->actingAs($agent)->get('/tickets?sort=priority')))->toBe(['Urgente nueva', 'Urgente vieja', 'Alta', 'Normal', 'Baja reciente']);
    });

    it('cambiar la prioridad de un ticket cambia su lugar sin más trabajo', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $ticket = titled($tenant, 'Sube', ['priority' => TicketPriority::Low]);
        titled($tenant, 'Se queda', ['priority' => TicketPriority::Normal]);

        $this->actingAs($agent)->patch("/tickets/{$ticket->id}", ['priority' => 'urgent'])->assertRedirect();

        expect(inboxSubjects($this->actingAs($agent)->get('/tickets?sort=priority')))->toBe(['Sube', 'Se queda']);
    });

    it('el orden no depende de que dos tickets tengan la misma hora', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $same = '2026-10-20 10:00:00';
        $first = titled($tenant, 'Primero creado', ['last_message_at' => $same]);
        $second = titled($tenant, 'Segundo creado', ['last_message_at' => $same]);

        expect($second->id)->toBeGreaterThan($first->id)
            ->and(inboxSubjects($this->actingAs($agent)->get('/tickets')))->toBe(['Segundo creado', 'Primero creado']);
    });
});

describe('carga progresiva', function (): void {
    it('la primera carga trae 30 tickets y un cursor para el resto', function (): void {
        [, $agent] = manyTickets();

        $response = $this->actingAs($agent)->get('/tickets');

        expect(inboxRows($response))->toHaveCount(30)
            ->and(inboxProp($response, 'next_cursor'))->toBeString()
            ->and(inboxProp($response, 'total'))->toBe(35)
            ->and(inboxSubjects($response)[0])->toBe('Ticket 35');
    });

    it('los tramos siguientes continúan sin repetir ni saltarse ninguno', function (): void {
        [, $agent] = manyTickets();
        $first = $this->actingAs($agent)->get('/tickets');
        $cursor = inboxProp($first, 'next_cursor');

        $second = $this->actingAs($agent)->getJson('/tickets/page?cursor='.urlencode($cursor))->assertOk();

        $all = [...inboxSubjects($first), ...array_column($second->json('data'), 'subject')];
        expect($second->json('data'))->toHaveCount(5)
            ->and($second->json('next_cursor'))->toBeNull()
            ->and($all)->toHaveCount(35)->and(array_unique($all))->toHaveCount(35)
            ->and($all[34])->toBe('Ticket 1');
    });

    it('un tramo exacto de 30 no deja un cursor vacío', function (): void {
        [, $agent] = manyTickets(30);

        $response = $this->actingAs($agent)->get('/tickets');

        expect(inboxRows($response))->toHaveCount(30)->and(inboxProp($response, 'next_cursor'))->toBeNull();
    });

    it('los tramos respetan los mismos filtros y el mismo orden', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        foreach (range(1, 35) as $i) {
            titled($tenant, "Urgente {$i}", ['priority' => TicketPriority::Urgent, 'last_message_at' => Carbon::parse('2026-10-01')->addMinutes($i)]);
            titled($tenant, "Baja {$i}", ['priority' => TicketPriority::Low, 'last_message_at' => Carbon::parse('2026-10-01')->addMinutes($i)]);
        }
        $first = $this->actingAs($agent)->get('/tickets?priority[]=urgent&sort=oldest');
        $cursor = inboxProp($first, 'next_cursor');

        $second = $this->actingAs($agent)->getJson('/tickets/page?priority[]=urgent&sort=oldest&cursor='.urlencode($cursor));

        expect(inboxSubjects($first)[0])->toBe('Urgente 1')
            ->and(array_column($second->json('data'), 'subject'))->toBe(['Urgente 31', 'Urgente 32', 'Urgente 33', 'Urgente 34', 'Urgente 35']);
    });

    it('un cursor inventado no abre nada más: cae en el primer tramo de la propia empresa', function (string $cursor): void {
        [, $agent] = manyTickets(3);
        makeTicket(ticketTenant(), ['subject' => 'De otra empresa']);

        $response = $this->actingAs($agent)->getJson('/tickets/page?cursor='.urlencode($cursor))->assertOk();

        expect(array_column($response->json('data'), 'subject'))->toBe(['Ticket 3', 'Ticket 2', 'Ticket 1']);
    })->with([
        'texto cualquiera' => ['esto-no-es-un-cursor'],
        'base64 de basura' => [base64_encode('{"no":"es-el-formato"}')],
        'identificador enorme' => [base64_encode('{"last_message_at":"2099-01-01 00:00:00","id":99999999,"_pointsToNextItems":true}')],
        'columnas de otro orden' => [base64_encode('{"priority_rank":4,"id":1,"_pointsToNextItems":true}')],
    ]);

    it('los tramos no hacen consultas de más por cada fila', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        foreach (range(1, 5) as $i) {
            titled($tenant, "Ticket {$i}");
        }
        $this->actingAs($agent)->getJson('/tickets/page');

        DB::enableQueryLog();
        $this->actingAs($agent)->getJson('/tickets/page')->assertOk();
        $few = count(DB::getQueryLog());
        DB::flushQueryLog();

        foreach (range(6, 30) as $i) {
            titled($tenant, "Ticket {$i}");
        }
        DB::flushQueryLog();
        $this->actingAs($agent)->getJson('/tickets/page')->assertOk();
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($many)->toBe($few);
    });
});

describe('contadores', function (): void {
    it('cada filtro rápido cuenta lo que mostraría', function (): void {
        $tenant = ticketTenant();
        $me = ticketStaff($tenant);
        $other = ticketStaff($tenant);
        titled($tenant, 'Mío 1', ['assigned_to' => $me->id]);
        titled($tenant, 'Mío 2', ['assigned_to' => $me->id, 'status' => TicketStatus::WaitingCustomer]);
        titled($tenant, 'Mío cerrado', ['assigned_to' => $me->id, 'status' => TicketStatus::Closed]);
        titled($tenant, 'Libre 1');
        titled($tenant, 'Libre 2');
        titled($tenant, 'Libre 3');
        titled($tenant, 'De otro', ['assigned_to' => $other->id]);

        $response = $this->actingAs($me)->get('/tickets');

        expect(inboxProp($response, 'counts'))->toBe(['all' => 7, 'mine' => 2, 'unassigned' => 3]);
    });

    it('el contador de «Mis tickets» es el de quien mira, no el de otra persona', function (): void {
        $tenant = ticketTenant();
        $me = ticketStaff($tenant);
        $other = ticketStaff($tenant);
        titled($tenant, 'Del otro', ['assigned_to' => $other->id]);

        expect(inboxProp($this->actingAs($me)->get('/tickets'), 'counts.mine'))->toBe(0)
            ->and(inboxProp($this->actingAs($other)->get('/tickets'), 'counts.mine'))->toBe(1);
    });

    it('la barra lateral recibe cuántos tickets pendientes nadie atiende, en cualquier pantalla del panel', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        titled($tenant, 'Libre 1');
        titled($tenant, 'Libre 2', ['status' => TicketStatus::WaitingCustomer]);
        titled($tenant, 'Libre cerrado', ['status' => TicketStatus::Closed]);
        titled($tenant, 'Con dueño', ['assigned_to' => $agent->id]);

        $this->actingAs($agent)->get('/account')->assertInertia(fn ($page) => $page->where('auth.inbox.unassigned', 2));
        $this->actingAs($agent)->get('/tickets')->assertInertia(fn ($page) => $page->where('auth.inbox.unassigned', 2));
    });

    it('el contador de la barra solo cuenta la propia empresa', function (): void {
        $tenant = ticketTenant();
        titled(ticketTenant(), 'De otra empresa');
        titled(ticketTenant(), 'De otra empresa 2');

        $this->actingAs(ticketStaff($tenant))->get('/account')->assertInertia(fn ($page) => $page->where('auth.inbox.unassigned', 0));
    });

    it('el personal de la plataforma no recibe contador', function (): void {
        $staff = User::factory()->create(['is_platform_admin' => true]);
        $staff->forceFill(['two_factor_secret' => encrypt('S'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()])->save();

        $this->actingAs($staff)->get('/account')->assertInertia(fn ($page) => $page->where('auth.inbox.unassigned', null));
    });

    it('el observador también ve el contador', function (): void {
        $tenant = ticketTenant();
        titled($tenant, 'Libre');

        $this->actingAs(ticketStaff($tenant, Role::Observer))->get('/account')->assertInertia(fn ($page) => $page->where('auth.inbox.unassigned', 1));
    });
});

describe('aislamiento entre empresas', function (): void {
    it('ningún filtro deja ver tickets de otra empresa', function (): void {
        $mine = ticketTenant();
        $theirs = ticketTenant();
        $agent = ticketStaff($mine);
        $stranger = ticketStaff($theirs);
        titled($mine, 'Propio');
        titled($theirs, 'Ajeno', ['assigned_to' => $stranger->id, 'priority' => TicketPriority::Urgent]);
        $foreignTag = makeTag($theirs, ['name' => 'Ajena']);

        foreach (['', "?agent={$stranger->id}", '?priority[]=urgent', "?tag={$foreignTag->id}", '?scope=mine', '?scope=unassigned', '?sort=priority', '?sort=oldest'] as $query) {
            expect(inboxSubjects($this->actingAs($agent)->get("/tickets{$query}")))->not->toContain('Ajeno');
        }
    });

    it('los contadores y el total solo cuentan la propia empresa', function (): void {
        $mine = ticketTenant();
        $agent = ticketStaff($mine);
        titled($mine, 'Propio');
        foreach (range(1, 4) as $i) {
            titled(ticketTenant(), "Ajeno {$i}");
        }

        $response = $this->actingAs($agent)->get('/tickets');

        expect(inboxProp($response, 'counts'))->toBe(['all' => 1, 'mine' => 0, 'unassigned' => 1])->and(inboxProp($response, 'total'))->toBe(1);
    });

    it('los tramos siguientes tampoco mezclan empresas', function (): void {
        $mine = ticketTenant();
        $agent = ticketStaff($mine);
        foreach (range(1, 31) as $i) {
            titled($mine, "Propio {$i}", ['last_message_at' => Carbon::parse('2026-10-01')->addMinutes($i)]);
            titled(ticketTenant(), "Ajeno {$i}", ['last_message_at' => Carbon::parse('2026-10-01')->addMinutes($i)]);
        }
        $first = $this->actingAs($agent)->get('/tickets');

        $second = $this->actingAs($agent)->getJson('/tickets/page?cursor='.urlencode(inboxProp($first, 'next_cursor')));

        expect(array_column($second->json('data'), 'subject'))->toBe(['Propio 1']);
    });
});

describe('permisos', function (): void {
    it('el observador usa la bandeja con filtros, orden y tramos', function (): void {
        $tenant = ticketTenant();
        titled($tenant, 'Algo');
        $observer = ticketStaff($tenant, Role::Observer);

        $this->actingAs($observer)->get('/tickets?status[]=open&sort=priority')->assertOk();
        $this->actingAs($observer)->getJson('/tickets/page')->assertOk();
    });

    it('un visitante, un desactivado y el personal de la plataforma no entran a los tramos', function (): void {
        $tenant = ticketTenant();
        $staff = User::factory()->create(['is_platform_admin' => true]);
        $staff->forceFill(['two_factor_secret' => encrypt('S'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()])->save();

        $this->getJson('/tickets/page')->assertUnauthorized();
        $this->actingAs(ticketStaff($tenant, Role::Agent, disabled: true))->getJson('/tickets/page')->assertRedirect(route('login'));
        $this->actingAs($staff)->getJson('/tickets/page')->assertForbidden();
    });

    it('los tramos no se guardan en caché del navegador', function (): void {
        $this->actingAs(ticketStaff(ticketTenant()))->getJson('/tickets/page')->assertHeader('Cache-Control', 'no-store, private');
    });

    it('limita la bandeja a 300 por minuto sin búsqueda', function (): void {
        $agent = ticketStaff(ticketTenant());

        foreach (range(1, 300) as $ignored) {
            $this->actingAs($agent)->getJson('/tickets/page')->assertOk();
        }

        $this->actingAs($agent)->getJson('/tickets/page')->assertTooManyRequests();
    });

    it('la búsqueda tiene un tope propio de 60 por minuto', function (): void {
        $agent = ticketStaff(ticketTenant());

        foreach (range(1, 60) as $ignored) {
            $this->actingAs($agent)->getJson('/tickets/page?q=factura')->assertOk();
        }

        $this->actingAs($agent)->getJson('/tickets/page?q=factura')->assertTooManyRequests();
        $this->actingAs($agent)->getJson('/tickets/page')->assertOk();
    });
});

it('abrir un ticket también aplica los filtros a la lista de al lado', function (): void {
    $tenant = ticketTenant();
    $agent = ticketStaff($tenant);
    $open = titled($tenant, 'Abierto');
    titled($tenant, 'Cerrado', ['status' => TicketStatus::Closed]);

    $response = $this->actingAs($agent)->get("/tickets/{$open->id}?status[]=open");

    expect(inboxSubjects($response))->toBe(['Abierto'])->and(inboxProp($response, 'selected.id'))->toBe($open->id);
});

it('un ticket de otra empresa sigue dando 404 aunque se pidan filtros', function (): void {
    $ticket = makeTicket(ticketTenant());

    $this->assertOtherTenantGets404(ticketStaff(ticketTenant()), 'GET', "/tickets/{$ticket->id}?status[]=open");
});

it('el total y la lista se calculan con consultas fijas', function (): void {
    $tenant = ticketTenant();
    $agent = ticketStaff($tenant);
    foreach (range(1, 5) as $i) {
        titled($tenant, "Ticket {$i}");
    }
    $this->actingAs($agent)->get('/tickets');

    DB::enableQueryLog();
    $this->actingAs($agent)->get('/tickets')->assertOk();
    $few = count(DB::getQueryLog());
    DB::flushQueryLog();

    foreach (range(6, 29) as $i) {
        titled($tenant, "Ticket {$i}");
    }
    DB::flushQueryLog();
    $this->actingAs($agent)->get('/tickets')->assertOk();
    $many = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($many)->toBe($few);
});

it('los tickets creados fuera de contexto no aparecen: sin empresa nada se lee', function (): void {
    $tenant = ticketTenant();
    titled($tenant, 'Algo');

    expect(app(TenantContext::class)->run($tenant, fn () => Ticket::query()->count()))->toBe(1)
        ->and(Ticket::query()->count())->toBe(0);
});
