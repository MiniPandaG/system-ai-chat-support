<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Actions\AddApiMessage;
use App\Actions\AddTicketMessage;
use App\Actions\SendAiMessage;
use App\Ai\AiRunStatus;
use App\Metrics\Duration;
use App\Metrics\Range;
use App\Metrics\SupportMetrics;
use App\Models\AiCall;
use App\Models\AiUsageDaily;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Tenancy\TenantContext;
use App\Tickets\Channel;
use App\Tickets\MessageAuthor;
use App\Tickets\TicketStatus;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    // Miércoles 4 de noviembre de 2026, 10:00 en Bogotá (UTC-5).
    Carbon::setTestNow('2026-11-04 15:00:00 UTC');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return array<string, mixed> */
function metricReport(Tenant $tenant, string $preset = '7d', ?string $from = null, ?string $to = null): array
{
    return app(TenantContext::class)->run($tenant, fn (): array => app(SupportMetrics::class)->report(Range::make($preset, $from, $to)));
}

describe('el rango de fechas', function (): void {
    it('«hoy», «7 días» y «30 días» son días completos en la zona de la plataforma, convertidos a UTC', function (string $preset, string $from, string $to): void {
        $range = Range::make($preset);

        expect($range->fromUtc()->toDateTimeString())->toBe($from)->and($range->toUtc()->toDateTimeString())->toBe($to);
    })->with([
        'hoy' => ['today', '2026-11-04 05:00:00', '2026-11-05 04:59:59'],
        '7 días' => ['7d', '2026-10-29 05:00:00', '2026-11-05 04:59:59'],
        '30 días' => ['30d', '2026-10-06 05:00:00', '2026-11-05 04:59:59'],
    ]);

    it('uno personalizado incluye los dos extremos, y el anterior tiene los mismos días justo antes', function (): void {
        $range = Range::make('custom', '2026-11-01', '2026-11-03');
        $previous = $range->previous();

        expect($range->days())->toBe(3)->and($range->dates())->toBe(['2026-11-01', '2026-11-02', '2026-11-03'])
            ->and($previous->days())->toBe(3)->and($previous->fromUtc()->toDateTimeString())->toBe('2026-10-29 05:00:00')->and($previous->toUtc()->toDateTimeString())->toBe('2026-11-01 04:59:59');
    });

    it('rechaza lo que no es un rango válido', function (array $arguments): void {
        expect(fn () => Range::make(...$arguments))->toThrow(InvalidArgumentException::class);
    })->with([
        'preajuste desconocido' => [['year']],
        'sin fechas' => [['custom']],
        'inicio después del fin' => [['custom', '2026-11-03', '2026-11-01']],
        'fin en el futuro' => [['custom', '2026-11-01', '2026-11-05']],
        'fecha que no existe' => [['custom', '2026-02-30', '2026-03-01']],
        'formato incorrecto' => [['custom', '04/11/2026', '2026-11-04']],
        'con hora' => [['custom', '2026-11-01 10:00', '2026-11-04']],
        'más de 366 días' => [['custom', '2025-10-01', '2026-11-04']],
    ]);

    it('acepta exactamente 366 días', function (): void {
        expect(Range::make('custom', '2025-11-04', '2026-11-04')->days())->toBe(366);
    });
});

describe('las duraciones', function (): void {
    it('se dicen como las lee una persona', function (?int $seconds, string $label): void {
        expect(Duration::label($seconds))->toBe($label);
    })->with([
        'sin dato' => [null, '—'],
        'segundos' => [45, 'menos de 1 min'],
        'un minuto' => [60, '1 min'],
        'minutos' => [59 * 60 + 59, '59 min'],
        'una hora justa' => [3600, '1 h'],
        'horas y minutos' => [3 * 3600 + 5 * 60, '3 h 5 min'],
        'casi dos días' => [47 * 3600 + 59 * 60, '47 h 59 min'],
        'dos días' => [2 * 86400, '2 d'],
        'días y horas' => [2 * 86400 + 4 * 3600 + 30 * 60, '2 d 4 h'],
        'negativo' => [-5, 'menos de 1 min'],
    ]);

    it('la mediana de una cantidad impar es el del medio y la de una par, el promedio de los dos del medio', function (): void {
        expect(Duration::median([]))->toBeNull()->and(Duration::median([5]))->toBe(5)->and(Duration::median([900, 60, 120]))->toBe(120)->and(Duration::median([10, 20, 30, 41]))->toBe(25)
            ->and(Duration::median([10, 11]))->toBe(11);
    });
});

describe('tickets recibidos, resueltos y abiertos', function (): void {
    it('cuenta lo del rango con los bordes incluidos y deja fuera lo de antes y lo de después', function (): void {
        $tenant = ticketTenant();
        metricTicket($tenant, ['created' => '2026-10-29 04:59:59']);
        metricTicket($tenant, ['created' => '2026-10-29 05:00:00']);
        metricTicket($tenant, ['created' => '2026-11-04 15:00:00']);
        metricTicket($tenant, ['created' => '2026-11-05 04:59:59']);
        metricTicket($tenant, ['created' => '2026-11-05 05:00:00']);

        $cards = metricReport($tenant)['cards'];

        expect($cards['received']['value'])->toBe(3);
    });

    it('«resueltos» sigue la fecha de resolución, no la de creación, y cuenta también los cerrados', function (): void {
        $tenant = ticketTenant();
        metricTicket($tenant, ['created' => '2026-09-01 10:00:00', 'resolved' => '2026-11-03 12:00:00']);
        metricTicket($tenant, ['created' => '2026-11-03 10:00:00', 'resolved' => '2026-11-12 12:00:00']);
        metricTicket($tenant, ['created' => '2026-11-03 10:00:00']);

        $cards = metricReport($tenant)['cards'];

        expect($cards['resolved']['value'])->toBe(1)->and($cards['received']['value'])->toBe(2);
    });

    it('«abiertos ahora» cuenta los abiertos y los que esperan al cliente, sin importar el rango', function (): void {
        $tenant = ticketTenant();
        metricTicket($tenant, ['created' => '2025-01-01 10:00:00'], TicketStatus::Open);
        metricTicket($tenant, ['created' => '2026-11-03 10:00:00'], TicketStatus::WaitingCustomer);
        metricTicket($tenant, ['created' => '2026-11-03 10:00:00', 'resolved' => '2026-11-03 12:00:00']);
        metricTicket($tenant, ['created' => '2026-11-03 10:00:00'], TicketStatus::Closed);

        expect(metricReport($tenant, 'today')['open_now'])->toBe(2);
    });

    it('compara con el periodo anterior: porcentaje de cambio, y nada cuando antes no había', function (): void {
        $tenant = ticketTenant();
        foreach (['2026-10-30 12:00:00', '2026-11-01 12:00:00', '2026-11-02 12:00:00'] as $at) {
            metricTicket($tenant, ['created' => $at]);
        }
        metricTicket($tenant, ['created' => '2026-10-24 12:00:00']);
        metricTicket($tenant, ['created' => '2026-10-25 12:00:00']);

        $received = metricReport($tenant)['cards']['received'];
        $none = metricReport(ticketTenant())['cards']['received'];

        expect($received)->toMatchArray(['value' => 3, 'previous' => 2, 'delta' => 50, 'unit' => 'percent', 'better' => 'up'])
            ->and($none)->toMatchArray(['value' => 0, 'previous' => 0, 'delta' => null]);
    });

    it('lo de otra empresa nunca se cuenta', function (): void {
        $mine = ticketTenant();
        $other = ticketTenant();
        metricTicket($mine, ['created' => '2026-11-03 12:00:00']);
        foreach (range(1, 4) as $_) {
            metricTicket($other, ['created' => '2026-11-03 12:00:00', 'resolved' => '2026-11-03 13:00:00']);
        }

        $report = metricReport($mine);

        expect($report['cards']['received']['value'])->toBe(1)->and($report['cards']['resolved']['value'])->toBe(0)->and($report['open_now'])->toBe(1);
    });
});

describe('tiempos de primera respuesta y de resolución', function (): void {
    it('son medianas: un valor extremo no las mueve, y los tickets sin respuesta no cuentan', function (): void {
        $tenant = ticketTenant();
        metricTicket($tenant, ['created' => '2026-11-03 10:00:00', 'first_response' => '2026-11-03 10:01:00']);
        metricTicket($tenant, ['created' => '2026-11-03 11:00:00', 'first_response' => '2026-11-03 11:02:00']);
        metricTicket($tenant, ['created' => '2026-11-03 12:00:00', 'first_response' => '2026-11-04 12:00:00']);
        metricTicket($tenant, ['created' => '2026-11-03 13:00:00']);

        $card = metricReport($tenant)['cards']['first_response'];

        expect($card['value'])->toBe(120)->and($card['label'])->toBe('2 min')->and($card['better'])->toBe('down')->and($card['sampled'])->toBeFalse();
    });

    it('con cantidad par promedia los dos del medio y sin datos dice «—»', function (): void {
        $tenant = ticketTenant();
        metricTicket($tenant, ['created' => '2026-11-03 10:00:00', 'first_response' => '2026-11-03 10:10:00']);
        metricTicket($tenant, ['created' => '2026-11-03 11:00:00', 'first_response' => '2026-11-03 11:20:00']);

        expect(metricReport($tenant)['cards']['first_response']['value'])->toBe(900)
            ->and(metricReport(ticketTenant())['cards']['first_response'])->toMatchArray(['value' => null, 'label' => '—', 'delta' => null]);
    });

    it('la resolución es de lo resuelto en el rango, medida desde que se creó el ticket', function (): void {
        $tenant = ticketTenant();
        metricTicket($tenant, ['created' => '2026-11-03 10:00:00', 'resolved' => '2026-11-03 12:00:00']);
        metricTicket($tenant, ['created' => '2026-10-01 10:00:00', 'resolved' => '2026-11-03 10:00:00']);
        metricTicket($tenant, ['created' => '2026-11-03 10:00:00', 'resolved' => '2026-11-05 12:00:00']);

        $card = metricReport($tenant)['cards']['resolution'];

        expect($card['value'])->toBe(1429200)->and($card['label'])->toBe('16 d 13 h')->and($card['better'])->toBe('down');
    });

    it('el cambio frente al periodo anterior de un tiempo es un porcentaje y bajar es mejorar', function (): void {
        $tenant = ticketTenant();
        metricTicket($tenant, ['created' => '2026-11-03 10:00:00', 'first_response' => '2026-11-03 10:05:00']);
        metricTicket($tenant, ['created' => '2026-10-25 10:00:00', 'first_response' => '2026-10-25 10:10:00']);

        $card = metricReport($tenant)['cards']['first_response'];

        expect($card)->toMatchArray(['value' => 300, 'previous' => 600, 'delta' => -50, 'better' => 'down']);
    });

    it('con más tickets que el tope usa los más recientes y lo avisa', function (): void {
        config(['metrics.max_rows' => 2]);
        $tenant = ticketTenant();
        metricTicket($tenant, ['created' => '2026-11-01 10:00:00', 'first_response' => '2026-11-02 10:00:00']);
        metricTicket($tenant, ['created' => '2026-11-02 10:00:00', 'first_response' => '2026-11-02 10:02:00']);
        metricTicket($tenant, ['created' => '2026-11-03 10:00:00', 'first_response' => '2026-11-03 10:04:00']);

        $report = metricReport($tenant);

        expect($report['cards']['first_response']['value'])->toBe(180)->and($report['cards']['first_response']['sampled'])->toBeTrue()->and($report['cards']['received']['value'])->toBe(3);
    });
});

describe('quién anota la primera respuesta', function (): void {
    function ticketAfterReply(Tenant $tenant, Closure $reply): Ticket
    {
        $ticket = metricTicket($tenant, ['created' => '2026-11-04 14:00:00']);
        Carbon::setTestNow('2026-11-04 14:10:00 UTC');
        $reply($ticket);

        return reloaded($ticket);
    }

    it('la respuesta de una persona del equipo la anota, una sola vez', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);

        $ticket = ticketAfterReply($tenant, function (Ticket $ticket) use ($tenant, $agent): void {
            app(TenantContext::class)->run($tenant, fn () => app(AddTicketMessage::class)($agent, $ticket, 'Hola, ya lo vemos'));
            Carbon::setTestNow('2026-11-04 14:30:00 UTC');
            app(TenantContext::class)->run($tenant, fn () => app(AddTicketMessage::class)($agent, $ticket->refresh(), 'Segunda respuesta'));
        });

        expect($ticket->first_response_at?->toDateTimeString())->toBe('2026-11-04 14:10:00');
    });

    it('un mensaje del cliente no es una respuesta', function (): void {
        $tenant = ticketTenant();

        $ticket = ticketAfterReply($tenant, function (Ticket $ticket) use ($tenant): void {
            app(TenantContext::class)->run($tenant, fn () => app(AddApiMessage::class)($ticket, MessageAuthor::Customer, 'Sigo esperando'));
        });

        expect($ticket->first_response_at)->toBeNull();
    });

    it('un mensaje de agente por la API sí', function (): void {
        $tenant = ticketTenant();

        $ticket = ticketAfterReply($tenant, function (Ticket $ticket) use ($tenant): void {
            app(TenantContext::class)->run($tenant, fn () => app(AddApiMessage::class)($ticket, MessageAuthor::Agent, 'Respuesta desde la integración'));
        });

        expect($ticket->first_response_at?->toDateTimeString())->toBe('2026-11-04 14:10:00');
    });

    it('la respuesta de la IA la anota, pero su aviso de «te atenderá una persona» no', function (): void {
        $tenant = ticketTenant();

        $answered = ticketAfterReply($tenant, fn (Ticket $ticket) => app(TenantContext::class)->run($tenant, fn () => app(SendAiMessage::class)($ticket, 'Tienes 30 días.')));
        $handoff = ticketAfterReply($tenant, fn (Ticket $ticket) => app(TenantContext::class)->run($tenant, fn () => app(SendAiMessage::class)($ticket, 'Una persona revisará tu caso.', false)));

        expect($answered->first_response_at?->toDateTimeString())->toBe('2026-11-04 14:10:00')->and($handoff->first_response_at)->toBeNull();
    });

    it('un borrador de la IA aprobado también cuenta', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);

        $ticket = ticketAfterReply($tenant, function (Ticket $ticket) use ($tenant, $agent): void {
            app(TenantContext::class)->run($tenant, fn () => app(AddTicketMessage::class)($agent, $ticket, 'Borrador aprobado', [], MessageAuthor::Ai));
        });

        expect($ticket->first_response_at?->toDateTimeString())->toBe('2026-11-04 14:10:00');
    });

});

describe('satisfacción', function (): void {
    it('promedio, cantidad y distribución solo de lo contestado en el rango', function (): void {
        $tenant = ticketTenant();
        foreach ([[5, '2026-11-03 10:00:00'], [5, '2026-11-03 11:00:00'], [4, '2026-11-02 10:00:00'], [1, '2026-11-01 10:00:00'], [3, '2026-10-01 10:00:00']] as [$score, $at]) {
            metricCsat($tenant, metricTicket($tenant), $score, null, $at);
        }

        $report = metricReport($tenant);

        expect($report['cards']['csat'])->toMatchArray(['value' => 3.8, 'count' => 4, 'better' => 'up', 'unit' => 'points'])
            ->and($report['csat']['distribution'])->toBe([1 => 1, 2 => 0, 3 => 0, 4 => 1, 5 => 2]);
    });

    it('una encuesta sin contestar no cuenta como respuesta pero sí como enviada', function (): void {
        $tenant = ticketTenant();
        csatResolve($tenant, makeTicket($tenant, ['channel' => Channel::Widget]));
        metricCsat($tenant, metricTicket($tenant), 5);

        $report = metricReport($tenant);

        expect($report['cards']['csat']['count'])->toBe(1)->and($report['csat']['sent'])->toBe(2);
    });

    it('sin respuestas no hay promedio', function (): void {
        expect(metricReport(ticketTenant())['cards']['csat'])->toMatchArray(['value' => null, 'count' => 0, 'delta' => null]);
    });

    it('la variación es en puntos frente al periodo anterior', function (): void {
        $tenant = ticketTenant();
        metricCsat($tenant, metricTicket($tenant), 5, null, '2026-11-03 10:00:00');
        metricCsat($tenant, metricTicket($tenant), 3, null, '2026-10-25 10:00:00');

        expect(metricReport($tenant)['cards']['csat'])->toMatchArray(['value' => 5.0, 'previous' => 3.0, 'delta' => 2.0]);
    });

    it('las respuestas de otra empresa no entran', function (): void {
        $mine = ticketTenant();
        $other = ticketTenant();
        metricCsat($other, metricTicket($other), 1);

        expect(metricReport($mine)['cards']['csat']['count'])->toBe(0);
    });
});

describe('porcentaje resuelto solo con la IA', function (): void {
    it('cuenta los resueltos cuyas respuestas fueron todas de la IA en modo automático', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $resolved = ['created' => '2026-11-03 10:00:00', 'resolved' => '2026-11-03 12:00:00'];

        $byAi = metricTicket($tenant, $resolved);
        metricAiMessage($tenant, $byAi, AiRunStatus::Sent);

        $withAgent = metricTicket($tenant, $resolved);
        metricAiMessage($tenant, $withAgent, AiRunStatus::Sent);
        metricMessage($tenant, $withAgent, MessageAuthor::Agent, $agent->id);

        $approved = metricTicket($tenant, $resolved);
        metricAiMessage($tenant, $approved, AiRunStatus::Approved, $agent->id);

        $handoffOnly = metricTicket($tenant, $resolved);
        metricAiMessage($tenant, $handoffOnly, AiRunStatus::Escalated);

        metricTicket($tenant, $resolved);

        $card = metricReport($tenant)['cards']['ai_resolved'];

        expect($card)->toMatchArray(['value' => 20, 'resolved' => 5, 'by_ai' => 1, 'unit' => 'points']);
    });

    it('sin tickets resueltos no hay porcentaje', function (): void {
        expect(metricReport(ticketTenant())['cards']['ai_resolved'])->toMatchArray(['value' => null, 'resolved' => 0, 'by_ai' => 0]);
    });

    it('lo resuelto fuera del rango o de otra empresa no cuenta', function (): void {
        $mine = ticketTenant();
        $other = ticketTenant();
        $old = metricTicket($mine, ['created' => '2026-09-01 10:00:00', 'resolved' => '2026-09-02 10:00:00']);
        metricAiMessage($mine, $old, AiRunStatus::Sent);
        $foreign = metricTicket($other, ['created' => '2026-11-03 10:00:00', 'resolved' => '2026-11-03 12:00:00']);
        metricAiMessage($other, $foreign, AiRunStatus::Sent);

        expect(metricReport($mine)['cards']['ai_resolved'])->toMatchArray(['value' => null, 'by_ai' => 0]);
    });
});

describe('costo de la IA', function (): void {
    it('suma el gasto, las llamadas y los créditos del rango, y nada de otra empresa', function (): void {
        $tenant = ticketTenant();
        $other = ticketTenant();
        foreach ([['2026-11-03', 1500, 3], ['2026-11-04', 500, 1], ['2026-09-01', 9000, 9]] as [$day, $cost, $calls]) {
            app(TenantContext::class)->run($tenant, function () use ($day, $cost, $calls): void {
                $row = new AiUsageDaily;
                $row->forceFill(['day' => $day, 'calls' => $calls, 'input_tokens' => 1, 'output_tokens' => 1, 'cost_micro' => $cost])->save();
            });
        }
        app(TenantContext::class)->run($other, function (): void {
            $row = new AiUsageDaily;
            $row->forceFill(['day' => '2026-11-03', 'calls' => 50, 'input_tokens' => 1, 'output_tokens' => 1, 'cost_micro' => 70000])->save();
        });
        app(TenantContext::class)->run($tenant, function (): void {
            foreach (['reply' => 'ok', 'summary' => 'ok'] as $purpose => $status) {
                $call = new AiCall;
                $call->forceFill(['purpose' => $purpose, 'provider' => 'fake', 'catalog_id' => 'fake-fast', 'model' => 'fake-fast', 'input_tokens' => 1, 'output_tokens' => 1, 'cost_micro' => 1, 'latency_ms' => 1, 'status' => $status])->save();
            }
        });

        expect(metricReport($tenant)['ai'])->toBe(['spent_micro' => 2000, 'spent' => 'US$ 0,0020', 'calls' => 4, 'credits' => 1]);
    });
});

describe('la tendencia diaria', function (): void {
    it('trae un punto por cada día del rango, con ceros donde no pasó nada', function (): void {
        $trend = metricReport(ticketTenant())['trend'];

        expect($trend)->toHaveCount(7)->and($trend[0])->toBe(['date' => '2026-10-29', 'received' => 0, 'resolved' => 0, 'first_response' => null])->and($trend[6]['date'])->toBe('2026-11-04');
    });

    it('cuenta cada ticket en el día de la zona de la plataforma, no en el día UTC', function (): void {
        $tenant = ticketTenant();
        // 03:00 UTC del 4 es las 22:00 del 3 en Bogotá.
        metricTicket($tenant, ['created' => '2026-11-04 03:00:00', 'first_response' => '2026-11-04 03:05:00']);
        metricTicket($tenant, ['created' => '2026-11-04 06:00:00', 'first_response' => '2026-11-04 06:10:00', 'resolved' => '2026-11-04 08:00:00']);

        $trend = collect(metricReport($tenant)['trend'])->keyBy('date');

        expect($trend['2026-11-03']['received'])->toBe(1)->and($trend['2026-11-03']['first_response'])->toBe(300)
            ->and($trend['2026-11-04']['received'])->toBe(1)->and($trend['2026-11-04']['first_response'])->toBe(600)->and($trend['2026-11-04']['resolved'])->toBe(1);
    });

    it('un rango de 366 días da 366 puntos', function (): void {
        expect(metricReport(ticketTenant(), 'custom', '2025-11-04', '2026-11-04')['trend'])->toHaveCount(366);
    });
});

describe('los comentarios de la encuesta', function (): void {
    function metricComments(Tenant $tenant, ?int $score = null, string $preset = '30d'): Paginator
    {
        return app(TenantContext::class)->run($tenant, fn () => app(SupportMetrics::class)->comments(Range::make($preset), $score));
    }

    it('trae solo los que tienen texto, del rango, de más nuevo a más viejo, con su ticket', function (): void {
        $tenant = ticketTenant();
        $first = metricTicket($tenant);
        metricCsat($tenant, $first, 5, 'Excelente atención', '2026-11-02 10:00:00');
        metricCsat($tenant, metricTicket($tenant), 2, 'Tardaron mucho', '2026-11-03 10:00:00');
        metricCsat($tenant, metricTicket($tenant), 4, null, '2026-11-03 11:00:00');
        metricCsat($tenant, metricTicket($tenant), 1, 'Muy viejo', '2026-08-01 10:00:00');

        $items = metricComments($tenant)->items();

        expect(array_map(fn ($row): string => (string) $row->comment, $items))->toBe(['Tardaron mucho', 'Excelente atención'])->and($items[1]->ticket?->id)->toBe($first->id);
    });

    it('se filtran por puntaje y pagina de 15 en 15', function (): void {
        $tenant = ticketTenant();
        foreach (range(1, 17) as $n) {
            metricCsat($tenant, metricTicket($tenant), $n % 2 === 0 ? 5 : 1, "Comentario {$n}", '2026-11-03 10:00:00');
        }

        expect(metricComments($tenant, 5)->items())->toHaveCount(8)->and(metricComments($tenant)->items())->toHaveCount(15)->and(metricComments($tenant)->hasMorePages())->toBeTrue();
    });

    it('los de otra empresa no aparecen', function (): void {
        $mine = ticketTenant();
        $other = ticketTenant();
        metricCsat($other, metricTicket($other), 1, 'Comentario ajeno');

        expect(metricComments($mine)->items())->toBe([]);
    });
});
