<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Enums\Role;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Tenancy\TenantContext;
use App\Tickets\MessageAuthor;
use App\Tickets\TicketStatus;
use Illuminate\Support\Facades\DB;

/*
 * El índice de texto de InnoDB solo ve las filas confirmadas: dentro de la transacción que envuelve cada prueba no
 * encontraría nada. Estas pruebas cierran esa transacción al empezar y borran lo que crearon al terminar.
 */

/** @return list<int> */
function searchTenantIds(?int $add = null, bool $reset = false): array
{
    static $ids = [];

    if ($reset) {
        $ids = [];
    }

    if ($add !== null) {
        $ids[] = $add;
    }

    return $ids;
}

function searchTenant(): Tenant
{
    $tenant = ticketTenant();
    searchTenantIds($tenant->id);

    return $tenant;
}

/** Un ticket con su cliente y su primer mensaje, con los textos que la búsqueda debe encontrar. */
function searchTicket(Tenant $tenant, string $subject, string $body, string $customer = 'Cliente Genérico', ?string $email = null, array $attributes = []): Ticket
{
    return app(TenantContext::class)->run($tenant, function () use ($tenant, $subject, $body, $customer, $email, $attributes): Ticket {
        $person = Customer::factory()->create(['name' => $customer, 'email' => $email]);
        $number = $tenant->last_ticket_number + 1;
        $tenant->forceFill(['last_ticket_number' => $number])->save();

        $ticket = Ticket::factory()->forCustomer($person)->create(['number' => $number, 'subject' => $subject, ...$attributes]);
        $message = new TicketMessage(['body' => $body]);
        $message->forceFill(['ticket_id' => $ticket->id, 'author' => MessageAuthor::Customer])->save();

        return $ticket;
    });
}

beforeEach(function (): void {
    DB::commit();
});

afterEach(function (): void {
    $ids = searchTenantIds();

    if ($ids !== []) {
        foreach (['ticket_tag', 'tags', 'ticket_notes', 'ticket_messages', 'tickets', 'ticket_views', 'customers'] as $table) {
            DB::table($table)->whereIn('tenant_id', $ids)->delete();
        }
        DB::table('users')->whereIn('tenant_id', $ids)->delete();
        DB::table('tenants')->whereIn('id', $ids)->delete();
    }

    searchTenantIds(reset: true);
});

describe('qué busca', function (): void {
    it('encuentra por una palabra del texto de un mensaje y dice que coincidió en el mensaje', function (): void {
        $tenant = searchTenant();
        searchTicket($tenant, 'Consulta uno', 'Necesito un reembolso del último cobro.');
        searchTicket($tenant, 'Consulta dos', 'Quiero cambiar mi contraseña.');

        $rows = inboxRows($this->actingAs(ticketStaff($tenant))->get('/tickets?q=reembolso'));

        expect(array_column($rows, 'subject'))->toBe(['Consulta uno'])->and($rows[0]['match'])->toBe('message');
    });

    it('encuentra por el asunto', function (): void {
        $tenant = searchTenant();
        searchTicket($tenant, 'Factura duplicada en octubre', 'Hola.');
        searchTicket($tenant, 'Otra cosa', 'Hola.');

        $rows = inboxRows($this->actingAs(ticketStaff($tenant))->get('/tickets?q=duplicada'));

        expect(array_column($rows, 'subject'))->toBe(['Factura duplicada en octubre'])->and($rows[0]['match'])->toBe('subject');
    });

    it('encuentra por el nombre del cliente y por su correo', function (): void {
        $tenant = searchTenant();
        searchTicket($tenant, 'Uno', 'Hola.', 'Marisol Quintero', 'marisol@cliente-ejemplo.com');
        searchTicket($tenant, 'Dos', 'Hola.', 'Julián Pardo', 'jpardo@otra-empresa.org');
        $agent = ticketStaff($tenant);

        $byName = inboxRows($this->actingAs($agent)->get('/tickets?q=quintero'));
        $byEmail = inboxRows($this->actingAs($agent)->get('/tickets?q=otra-empresa'));

        expect(array_column($byName, 'subject'))->toBe(['Uno'])->and($byName[0]['match'])->toBe('customer')
            ->and(array_column($byEmail, 'subject'))->toBe(['Dos'])->and($byEmail[0]['match'])->toBe('customer');
    });

    it('encuentra por el número de ticket, con o sin almohadilla', function (): void {
        $tenant = searchTenant();
        $first = searchTicket($tenant, 'Primero', 'Hola.');
        searchTicket($tenant, 'Segundo', 'Hola.');
        $agent = ticketStaff($tenant);

        $plain = inboxRows($this->actingAs($agent)->get("/tickets?q={$first->number}"));
        $hash = inboxRows($this->actingAs($agent)->get('/tickets?q=%23'.$first->number));

        expect(array_column($plain, 'subject'))->toBe(['Primero'])->and($plain[0]['match'])->toBe('number')
            ->and(array_column($hash, 'subject'))->toBe(['Primero']);
    });

    it('cada palabra vale como comienzo de palabra: «reembol» encuentra «reembolso»', function (): void {
        $tenant = searchTenant();
        searchTicket($tenant, 'Uno', 'Pido el reembolso completo.');

        expect(inboxSubjects($this->actingAs(ticketStaff($tenant))->get('/tickets?q=reembol')))->toBe(['Uno']);
    });

    it('no distingue mayúsculas ni acentos', function (): void {
        $tenant = searchTenant();
        searchTicket($tenant, 'Uno', 'Problema con la facturación del mes.');
        $agent = ticketStaff($tenant);

        expect(inboxSubjects($this->actingAs($agent)->get('/tickets?q=FACTURACION')))->toBe(['Uno'])
            ->and(inboxSubjects($this->actingAs($agent)->get('/tickets?q=facturación')))->toBe(['Uno']);
    });

    it('con varias palabras deben aparecer todas', function (): void {
        $tenant = searchTenant();
        searchTicket($tenant, 'Uno', 'La factura salió duplicada.');
        searchTicket($tenant, 'Dos', 'La factura salió bien.');
        searchTicket($tenant, 'Tres', 'Algo duplicado sin relación.');

        expect(inboxSubjects($this->actingAs(ticketStaff($tenant))->get('/tickets?q=factura+duplicada')))->toBe(['Uno']);
    });

    it('encuentra en cualquier mensaje del ticket, no solo en el primero', function (): void {
        $tenant = searchTenant();
        $ticket = searchTicket($tenant, 'Uno', 'Hola.');
        app(TenantContext::class)->run($tenant, function () use ($ticket): void {
            $reply = new TicketMessage(['body' => 'Ya procesamos tu devolución.']);
            $reply->forceFill(['ticket_id' => $ticket->id, 'author' => MessageAuthor::Agent])->save();
        });

        expect(inboxSubjects($this->actingAs(ticketStaff($tenant))->get('/tickets?q=devolución')))->toBe(['Uno']);
    });

    it('las notas internas no se buscan: no son parte de lo que el cliente dijo ni de lo que se le respondió', function (): void {
        $tenant = searchTenant();
        $ticket = searchTicket($tenant, 'Uno', 'Hola.');
        makeNote($ticket, ticketStaff($tenant), ['body' => 'secretointerno del equipo']);

        expect(inboxRows($this->actingAs(ticketStaff($tenant))->get('/tickets?q=secretointerno')))->toBe([]);
    });

    it('si coincide en varios campos, la leyenda sigue un orden fijo: número, asunto, cliente y mensaje', function (): void {
        $tenant = searchTenant();
        searchTicket($tenant, 'Garantía vencida', 'Mi garantía venció.', 'Garantía Pérez');
        searchTicket($tenant, 'Otra', 'La garantía no cubre eso.', 'Ana Torres');
        searchTicket($tenant, 'Una más', 'Hola.', 'Garantía Díaz');

        $rows = inboxRows($this->actingAs(ticketStaff($tenant))->get('/tickets?q=garantía'));
        $labels = array_column($rows, 'match', 'subject');

        expect($labels)->toBe(['Una más' => 'customer', 'Otra' => 'message', 'Garantía vencida' => 'subject']);
    });
});

describe('con filtros y paginación', function (): void {
    it('la búsqueda se combina con los filtros', function (): void {
        $tenant = searchTenant();
        searchTicket($tenant, 'Abierto', 'Problema de facturación.');
        searchTicket($tenant, 'Cerrado', 'Problema de facturación.', attributes: ['status' => TicketStatus::Closed]);

        $response = $this->actingAs(ticketStaff($tenant))->get('/tickets?q=facturación&status[]=closed');

        expect(inboxSubjects($response))->toBe(['Cerrado'])->and(inboxProp($response, 'total'))->toBe(1);
    });

    it('el total cuenta lo que encontró la búsqueda', function (): void {
        $tenant = searchTenant();
        foreach (range(1, 4) as $i) {
            searchTicket($tenant, "Con palabra {$i}", 'Texto con zanahoria.');
        }
        searchTicket($tenant, 'Sin palabra', 'Texto normal.');

        expect(inboxProp($this->actingAs(ticketStaff($tenant))->get('/tickets?q=zanahoria'), 'total'))->toBe(4);
    });

    it('los tramos siguientes mantienen la búsqueda y dicen en qué coincidió cada fila', function (): void {
        $tenant = searchTenant();
        foreach (range(1, 32) as $i) {
            searchTicket($tenant, "Ticket {$i}", 'Texto con zanahoria.', attributes: ['last_message_at' => now()->subMinutes(100 - $i)]);
        }
        searchTicket($tenant, 'Sin la palabra', 'Otra cosa.');
        $agent = ticketStaff($tenant);
        $first = $this->actingAs($agent)->get('/tickets?q=zanahoria');

        $second = $this->actingAs($agent)->getJson('/tickets/page?q=zanahoria&cursor='.urlencode(inboxProp($first, 'next_cursor')));

        expect(inboxRows($first))->toHaveCount(30)
            ->and($second->json('data'))->toHaveCount(2)
            ->and($second->json('data.0.match'))->toBe('message');
    });

    it('buscar no hace consultas de más por cada fila', function (): void {
        $tenant = searchTenant();
        searchTicket($tenant, 'Uno', 'Texto con zanahoria.');
        $agent = ticketStaff($tenant);
        $this->actingAs($agent)->getJson('/tickets/page?q=zanahoria');

        DB::enableQueryLog();
        $this->actingAs($agent)->getJson('/tickets/page?q=zanahoria')->assertOk();
        $one = count(DB::getQueryLog());
        DB::flushQueryLog();

        foreach (range(1, 20) as $i) {
            searchTicket($tenant, "Ticket {$i}", 'Texto con zanahoria.');
        }
        DB::flushQueryLog();
        $this->actingAs($agent)->getJson('/tickets/page?q=zanahoria')->assertOk();
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($many)->toBe($one);
    });
});

describe('texto raro', function (): void {
    it('los operadores del índice de texto no se interpretan: no rompen la consulta ni cambian lo que se busca', function (string $text): void {
        $tenant = searchTenant();
        searchTicket($tenant, 'Uno', 'Texto con zanahoria y papa.');

        $this->actingAs(ticketStaff($tenant))->get('/tickets?q='.urlencode($text))->assertOk();
    })->with([
        'más y menos' => ['+zanahoria -papa'],
        'comodines' => ['zana* ~papa >x <y'],
        'comillas y paréntesis' => ['"zanahoria" (papa)'],
        'arroba' => ['@8 zanahoria'],
        'solo símbolos' => ['+-*"()<>~@'],
        'inyección clásica' => ["'; DROP TABLE tickets; --"],
        'barra invertida' => ['zana\\horia'],
    ]);

    it('un texto de solo símbolos no encuentra nada en vez de devolverlo todo', function (): void {
        $tenant = searchTenant();
        searchTicket($tenant, 'Uno', 'Texto cualquiera.');

        expect(inboxRows($this->actingAs(ticketStaff($tenant))->get('/tickets?q='.urlencode('+-*"()'))))->toBe([]);
    });

    it('un carácter suelto no es una búsqueda: la lista no se limita', function (): void {
        $tenant = searchTenant();
        searchTicket($tenant, 'Uno', 'Hola.');
        searchTicket($tenant, 'Dos', 'Hola.');

        expect(inboxRows($this->actingAs(ticketStaff($tenant))->get('/tickets?q=a')))->toHaveCount(2);
    });

    it('los comodines de SQL no cuentan: «%» no encuentra todo', function (): void {
        $tenant = searchTenant();
        searchTicket($tenant, 'Uno', 'Hola.');

        expect(inboxRows($this->actingAs(ticketStaff($tenant))->get('/tickets?q='.urlencode('%%'))))->toBe([]);
    });

    it('un texto con HTML se busca como texto y la respuesta no lo interpreta', function (): void {
        $tenant = searchTenant();
        searchTicket($tenant, 'Uno', 'Escribió <b>negrita</b> en el chat.');

        $rows = inboxRows($this->actingAs(ticketStaff($tenant))->get('/tickets?q=negrita'));

        expect($rows)->toHaveCount(1)->and($rows[0]['subject'])->toBe('Uno');
    });
});

describe('aislamiento entre empresas', function (): void {
    it('la búsqueda nunca encuentra tickets, mensajes ni clientes de otra empresa', function (): void {
        $mine = searchTenant();
        $theirs = searchTenant();
        searchTicket($mine, 'Propio', 'Hola.');
        searchTicket($theirs, 'Ajeno secreto', 'Texto con zanahoria.', 'Zanahoria Ajena', 'zanahoria@ajena.com');

        foreach (['zanahoria', 'ajena', 'secreto'] as $term) {
            expect(inboxRows($this->actingAs(ticketStaff($mine))->get("/tickets?q={$term}")))->toBe([]);
        }
    });

    it('buscar por número solo mira los tickets de la propia empresa', function (): void {
        $mine = searchTenant();
        $theirs = searchTenant();
        $own = searchTicket($mine, 'Propio', 'Hola.');
        searchTicket($theirs, 'Ajeno', 'Hola.');

        expect(inboxSubjects($this->actingAs(ticketStaff($mine))->get("/tickets?q={$own->number}")))->toBe(['Propio']);
    });
});

describe('permisos', function (): void {
    it('el observador también puede buscar', function (): void {
        $tenant = searchTenant();
        searchTicket($tenant, 'Uno', 'Texto con zanahoria.');

        $rows = inboxRows($this->actingAs(ticketStaff($tenant, Role::Observer))->get('/tickets?q=zanahoria'));

        expect($rows)->toHaveCount(1);
    });
});
