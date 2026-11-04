<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Ai\AiBudget;
use App\Ai\AiPurpose;
use App\Ai\AiReason;
use App\Ai\AiRunStatus;
use App\Ai\Rules\Confidence;
use App\Ai\Rules\EscalationRules;
use App\Ai\Rules\Schedule;
use App\Ai\ScheduleMode;
use App\Models\AiCall;
use App\Models\AiRun;
use App\Models\AiSettings;
use App\Models\AiUsageDaily;
use App\Models\Tenant;
use App\Plans\Limit;
use App\Plans\PlanLimits;
use App\Tenancy\TenantContext;
use App\Tickets\MessageAuthor;
use Illuminate\Support\Carbon;

describe('el cliente pide una persona', function (): void {
    it('lo detecta en español y en inglés, con o sin acentos, mayúsculas o signos', function (string $text): void {
        expect(app(EscalationRules::class)->humanRequested($text))->toBeTrue();
    })->with([
        'quiero hablar con una persona',
        'QUIERO HABLAR CON UN AGENTE!!!',
        'Necesito un humano, por favor',
        'pásame con un asesor',
        'me pueden comunicar con alguien real',
        'quiero un agente',
        'prefiero un asesor de verdad',
        'agente real por favor',
        'no quiero hablar con un bot',
        'ya no quiero hablar con una máquina',
        'puedo hablar con un representante',
        'I want to talk to a human',
        'let me speak with a real person',
        'human please',
        'Hola, ¿me atiende una persona?',
    ]);

    it('no confunde una pregunta de producto con pedir una persona', function (string $text): void {
        expect(app(EscalationRules::class)->humanRequested($text))->toBeFalse();
    })->with([
        '¿Cuántos días tengo para hacer devoluciones de productos?',
        'Mi pedido llegó roto',
        '¿Tienen agentes de viaje en la tienda?',
        'La persona que me entregó el paquete fue amable',
        'El humano detrás del producto es genial',
        '¿Cuál es el horario de atención?',
        '',
    ]);
});

describe('los temas que escalan', function (): void {
    function settingsWithTopics(array $topics): AiSettings
    {
        return (new AiSettings)->forceFill(['escalate_topics' => $topics]);
    }

    it('detecta la palabra o la frase completa sin importar acentos ni mayúsculas', function (string $text): void {
        expect(app(EscalationRules::class)->topicMatched(settingsWithTopics(['reembolso', 'demanda legal']), $text))->toBeTrue();
    })->with(['Quiero un REEMBOLSO ya', 'voy a iniciar una demanda legal', 'necesito el reembólso de mi compra', 'reembolso.']);

    it('no salta con palabras que solo contienen el tema, ni sin temas', function (): void {
        $rules = app(EscalationRules::class);

        expect($rules->topicMatched(settingsWithTopics(['reembolso']), 'mi reembolsable es otro asunto'))->toBeFalse()
            ->and($rules->topicMatched(settingsWithTopics(['demanda legal']), 'una demanda a secas'))->toBeFalse()
            ->and($rules->topicMatched(settingsWithTopics([]), 'reembolso'))->toBeFalse()
            ->and($rules->topicMatched((new AiSettings)->forceFill(['escalate_topics' => null]), 'reembolso'))->toBeFalse();
    });

    it('un tema con signos de expresión regular se toma al pie de la letra', function (): void {
        $rules = app(EscalationRules::class);

        expect($rules->topicMatched(settingsWithTopics(['a.*b']), 'axxb'))->toBeFalse()->and($rules->topicMatched(settingsWithTopics(['a.*b']), 'dijo a.*b ayer'))->toBeTrue();
    });
});

describe('el límite de mensajes de la IA por ticket', function (): void {
    it('cuenta lo que la IA ya envió o lo que una persona aprobó, y nada más', function (): void {
        $tenant = ticketTenant();
        $ticket = makeTicket($tenant);
        $settings = (new AiSettings)->forceFill(['max_messages' => 2]);
        $rules = app(EscalationRules::class);

        $add = function (AiRunStatus $status) use ($tenant, $ticket): void {
            $trigger = conversationMessage($tenant, $ticket, MessageAuthor::Customer, 'Hola');
            app(TenantContext::class)->run($tenant, function () use ($ticket, $trigger, $status): void {
                $run = new AiRun;
                $run->forceFill(['ticket_id' => $ticket->id, 'trigger_message_id' => $trigger->id, 'status' => $status, 'reason' => AiReason::Answered])->save();
            });
        };

        $check = fn (): bool => app(TenantContext::class)->run($tenant, fn (): bool => $rules->messageLimitReached($ticket, $settings));

        $add(AiRunStatus::Drafted);
        $add(AiRunStatus::Discarded);
        $add(AiRunStatus::Escalated);
        expect($check())->toBeFalse();

        $add(AiRunStatus::Sent);
        expect($check())->toBeFalse();

        $add(AiRunStatus::Approved);
        expect($check())->toBeTrue();
    });

    it('lo que se envió en otro ticket no cuenta', function (): void {
        $tenant = ticketTenant();
        $other = makeTicket($tenant);
        $ticket = makeTicket($tenant);
        $trigger = conversationMessage($tenant, $other, MessageAuthor::Customer, 'Hola');
        app(TenantContext::class)->run($tenant, function () use ($other, $trigger): void {
            $run = new AiRun;
            $run->forceFill(['ticket_id' => $other->id, 'trigger_message_id' => $trigger->id, 'status' => AiRunStatus::Sent, 'reason' => AiReason::Answered])->save();
        });

        $reached = app(TenantContext::class)->run($tenant, fn (): bool => app(EscalationRules::class)->messageLimitReached($ticket, (new AiSettings)->forceFill(['max_messages' => 1])));

        expect($reached)->toBeFalse();
    });
});

describe('el horario de la IA', function (): void {
    function scheduled(ScheduleMode $mode, array $days = [1, 2, 3, 4, 5], string $start = '09:00', string $end = '18:00', string $zone = 'America/Bogota'): AiSettings
    {
        return (new AiSettings)->forceFill(['schedule_mode' => $mode, 'schedule_days' => $days, 'schedule_start' => $start, 'schedule_end' => $end, 'schedule_timezone' => $zone]);
    }

    it('«siempre» no mira la hora', function (): void {
        expect(app(Schedule::class)->allows(scheduled(ScheduleMode::Always, []), Carbon::parse('2026-11-08 03:00:00', 'UTC')))->toBeTrue();
    });

    it('«solo dentro del horario» responde de lunes a viernes en la franja, en la zona de la empresa', function (string $utc, bool $expected): void {
        // 2026-11-03 es martes. Bogotá es UTC-5.
        expect(app(Schedule::class)->allows(scheduled(ScheduleMode::Within), Carbon::parse($utc, 'UTC')))->toBe($expected);
    })->with([
        'martes a las 10:00 en Bogotá' => ['2026-11-03 15:00:00', true],
        'martes justo al inicio, 09:00' => ['2026-11-03 14:00:00', true],
        'martes justo al fin, 18:00 ya no' => ['2026-11-03 23:00:00', false],
        'martes a las 07:00' => ['2026-11-03 12:00:00', false],
        'sábado a las 10:00' => ['2026-11-07 15:00:00', false],
        'el lunes a las 8 pm UTC ya es lunes 3 pm en Bogotá' => ['2026-11-02 20:00:00', true],
        'la madrugada UTC del sábado sigue siendo viernes por la noche en Bogotá' => ['2026-11-07 01:00:00', false],
    ]);

    it('«solo fuera del horario» es lo contrario', function (): void {
        $outside = scheduled(ScheduleMode::Outside);

        expect(app(Schedule::class)->allows($outside, Carbon::parse('2026-11-03 15:00:00', 'UTC')))->toBeFalse()
            ->and(app(Schedule::class)->allows($outside, Carbon::parse('2026-11-07 15:00:00', 'UTC')))->toBeTrue();
    });

    it('sin días elegidos, «dentro» nunca y «fuera» siempre', function (): void {
        $at = Carbon::parse('2026-11-03 15:00:00', 'UTC');

        expect(app(Schedule::class)->allows(scheduled(ScheduleMode::Within, []), $at))->toBeFalse()->and(app(Schedule::class)->allows(scheduled(ScheduleMode::Outside, []), $at))->toBeTrue();
    });

    it('una zona horaria desconocida se trata como UTC en vez de fallar', function (): void {
        expect(app(Schedule::class)->allows(scheduled(ScheduleMode::Within, [2], '09:00', '18:00', 'Marte/Olympus'), Carbon::parse('2026-11-03 10:00:00', 'UTC')))->toBeTrue();
    });
});

describe('la confianza', function (): void {
    // Una escala fija para estas pruebas: la de fábrica se recalibra con datos reales y no debe romperlas.
    beforeEach(fn () => config(['ai.confidence.floor' => 0.75, 'ai.confidence.ceil' => 0.88]));

    it('sube con lo parecido que es el mejor fragmento citado, en la escala configurada', function (array $scores, int $expected): void {
        expect(app(Confidence::class)->score($scores, false))->toBe($expected);
    })->with([
        'por debajo del piso' => [[0.60], 0],
        'justo en el piso' => [[0.75], 0],
        'a la mitad' => [[0.815], 50],
        'en el techo' => [[0.88], 100],
        'por encima del techo' => [[0.95], 100],
        'cuenta el mejor de varios' => [[0.76, 0.88, 0.70], 100],
    ]);

    it('una respuesta sin fragmentos, solo con el contexto de la empresa, tiene la confianza fija configurada', function (): void {
        expect(app(Confidence::class)->score([], false))->toBe(75);
    });

    it('si el propio modelo pidió una persona no pasa del tope, por seguro que parezca', function (): void {
        expect(app(Confidence::class)->score([0.95], true))->toBe(40)->and(app(Confidence::class)->score([0.76], true))->toBe(8);
    });

    it('la escala se puede calibrar sin tocar código', function (): void {
        config(['ai.confidence.floor' => 0.5, 'ai.confidence.ceil' => 0.6]);

        expect(app(Confidence::class)->score([0.55], false))->toBe(50);
    });
});

describe('el tope de gasto y los créditos', function (): void {
    function spend(Tenant $tenant, int $micro, string $day = 'today'): void
    {
        app(TenantContext::class)->run($tenant, function () use ($micro, $day): void {
            $row = new AiUsageDaily;
            $row->forceFill(['day' => $day === 'today' ? now()->utc()->toDateString() : $day, 'calls' => 1, 'input_tokens' => 10, 'output_tokens' => 10, 'cost_micro' => $micro])->save();
        });
    }

    function budgetFor(Tenant $tenant, ?AiSettings $settings = null): ?AiReason
    {
        return app(TenantContext::class)->run($tenant, fn (): ?AiReason => app(AiBudget::class)->pausedReason($settings ?? AiSettings::current()));
    }

    it('sin gasto la IA trabaja', function (): void {
        expect(budgetFor(ticketTenant()))->toBeNull();
    });

    it('el tope diario de fábrica (US$ 2) pausa la IA al alcanzarlo', function (): void {
        $tenant = ticketTenant();
        spend($tenant, 1_999_999);
        expect(budgetFor($tenant))->toBeNull();

        app(TenantContext::class)->run($tenant, fn () => AiUsageDaily::query()->update(['cost_micro' => 2_000_000]));

        expect(budgetFor($tenant))->toBe(AiReason::SpendLimit);
    });

    it('el tope que fijó la empresa manda sobre el de fábrica', function (): void {
        $tenant = ticketTenant();
        spend($tenant, 500_000);

        expect(budgetFor($tenant, (new AiSettings)->forceFill(['daily_spend_micro' => 500_000])))->toBe(AiReason::SpendLimit)
            ->and(budgetFor($tenant, (new AiSettings)->forceFill(['daily_spend_micro' => 500_001])))->toBeNull();
    });

    it('el tope mensual suma los días del mes y se pausa aunque cada día esté por debajo del suyo', function (): void {
        $tenant = ticketTenant();
        spend($tenant, 1_000_000, now()->utc()->startOfMonth()->toDateString());
        spend($tenant, 900_000);
        $settings = (new AiSettings)->forceFill(['daily_spend_micro' => 2_000_000, 'monthly_spend_micro' => 1_900_000]);

        expect(budgetFor($tenant, $settings))->toBe(AiReason::SpendLimit)
            ->and(budgetFor($tenant, (new AiSettings)->forceFill(['daily_spend_micro' => 2_000_000, 'monthly_spend_micro' => 1_900_001])))->toBeNull();
    });

    it('el gasto de un mes anterior no cuenta en el actual', function (): void {
        $tenant = ticketTenant();
        spend($tenant, 100_000_000, now()->utc()->startOfMonth()->subDay()->toDateString());

        expect(budgetFor($tenant))->toBeNull();
    });

    it('el gasto de una empresa no pausa a la otra', function (): void {
        $one = ticketTenant();
        $two = ticketTenant();
        spend($one, 5_000_000);

        expect(budgetFor($one))->toBe(AiReason::SpendLimit)->and(budgetFor($two))->toBeNull();
    });

    it('los créditos del plan se gastan con cada respuesta redactada, y los fallos y resúmenes no cuentan', function (): void {
        $tenant = ticketTenant('inicial');
        $limit = app(PlanLimits::class)->limit($tenant, Limit::AiCredits);
        expect($limit)->toBe(50);

        app(TenantContext::class)->run($tenant, function () use ($limit): void {
            foreach (range(1, $limit - 1) as $_) {
                aiCallRow(AiPurpose::Reply, 'ok');
            }
            aiCallRow(AiPurpose::Reply, 'error');
            aiCallRow(AiPurpose::Summary, 'ok');
        });
        expect(app(PlanLimits::class)->usage($tenant, Limit::AiCredits))->toBe($limit - 1)->and(budgetFor($tenant))->toBeNull();

        app(TenantContext::class)->run($tenant, fn () => aiCallRow(AiPurpose::Reply, 'ok'));

        expect(app(PlanLimits::class)->usage($tenant, Limit::AiCredits))->toBe($limit)->and(budgetFor($tenant))->toBe(AiReason::Credits);
    });

    it('las respuestas de un mes anterior no gastan los créditos de este', function (): void {
        $tenant = ticketTenant('inicial');
        app(TenantContext::class)->run($tenant, function (): void {
            $call = aiCallRow(AiPurpose::Reply, 'ok');
            AiCall::query()->whereKey($call->id)->update(['created_at' => now()->startOfMonth()->subDay()]);
        });

        expect(app(PlanLimits::class)->usage($tenant, Limit::AiCredits))->toBe(0);
    });

    function aiCallRow(AiPurpose $purpose, string $status): AiCall
    {
        $call = new AiCall;
        $call->forceFill(['purpose' => $purpose->value, 'provider' => 'fake', 'catalog_id' => 'fake-fast', 'model' => 'fake-fast', 'input_tokens' => 1, 'output_tokens' => 1, 'cost_micro' => 1, 'latency_ms' => 1, 'status' => $status])->save();

        return $call;
    }
});
