<?php

declare(strict_types=1);

namespace App\Metrics;

use App\Ai\AiPurpose;
use App\Models\AiCall;
use App\Models\AiUsageDaily;
use App\Models\CsatResponse;
use App\Models\Ticket;
use App\Tickets\MessageAuthor;
use App\Tickets\TicketStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\Paginator;

/**
 * Lo que mide el soporte de la empresa en contexto (RF-37, HU-12): tickets abiertos, recibidos y resueltos, tiempo de
 * primera respuesta y de resolución (medianas), satisfacción, porcentaje resuelto solo con la IA y su costo. Todo sale del
 * filtro automático de empresa. Las medianas se calculan sobre los tickets del rango (como mucho `metrics.max_rows`, los
 * más recientes) y las demás cifras son conteos exactos hechos por la base de datos.
 */
class SupportMetrics
{
    /**
     * @return array<string, mixed>
     */
    public function report(Range $range): array
    {
        $current = $this->figures($range);
        $previous = $this->figures($range->previous());

        return [
            'range' => ['from' => $range->from->toDateString(), 'to' => $range->to->toDateString(), 'days' => $range->days()],
            'open_now' => Ticket::query()->whereIn('status', [TicketStatus::Open->value, TicketStatus::WaitingCustomer->value])->count(),
            'cards' => [
                'received' => $this->card($current['received'], $previous['received'], 'percent', 'up'),
                'resolved' => $this->card($current['resolved'], $previous['resolved'], 'percent', 'up'),
                'first_response' => $this->card($current['first_response'], $previous['first_response'], 'percent', 'down') + ['label' => Duration::label($current['first_response']), 'sampled' => $current['sampled']],
                'resolution' => $this->card($current['resolution'], $previous['resolution'], 'percent', 'down') + ['label' => Duration::label($current['resolution']), 'sampled' => $current['sampled']],
                'csat' => $this->card($current['csat_average'], $previous['csat_average'], 'points', 'up') + ['count' => $current['csat_count']],
                'ai_resolved' => $this->card($current['ai_percent'], $previous['ai_percent'], 'points', 'up') + ['resolved' => $current['resolved'], 'by_ai' => $current['ai_resolved']],
            ],
            'csat' => ['sent' => $current['csat_sent'], 'distribution' => $current['csat_distribution']],
            'ai' => $this->aiUsage($range),
            'trend' => $this->trend($range, $current['created_rows'], $current['resolved_rows']),
        ];
    }

    /**
     * Los comentarios de la encuesta del rango, con el ticket al que pertenecen, de más nuevo a más viejo.
     *
     * @return Paginator<int, CsatResponse>
     */
    public function comments(Range $range, ?int $score): Paginator
    {
        return CsatResponse::query()
            ->with('ticket:id,number,subject')
            ->whereNotNull('comment')
            ->whereBetween('answered_at', [$range->fromUtc(), $range->toUtc()])
            ->when($score !== null, fn ($query) => $query->where('score', $score))
            ->orderByDesc('answered_at')
            ->orderByDesc('id')
            ->simplePaginate(config()->integer('metrics.comments_per_page'));
    }

    /**
     * @return array{received: int, resolved: int, first_response: int|null, resolution: int|null, sampled: bool, csat_average: float|null, csat_count: int, csat_sent: int, csat_distribution: array<int, int>, ai_resolved: int, ai_percent: int|null, created_rows: array<int, array{0: CarbonImmutable, 1: CarbonImmutable|null}>, resolved_rows: array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>}
     */
    private function figures(Range $range): array
    {
        $cap = config()->integer('metrics.max_rows');
        $created = Ticket::query()->whereBetween('created_at', [$range->fromUtc(), $range->toUtc()]);
        $resolved = Ticket::query()->whereBetween('resolved_at', [$range->fromUtc(), $range->toUtc()]);

        $received = (clone $created)->count();
        $resolvedCount = (clone $resolved)->count();

        $createdRows = (clone $created)->orderByDesc('created_at')->limit($cap)->get(['created_at', 'first_response_at'])
            ->map(fn (Ticket $ticket): array => [CarbonImmutable::instance($ticket->created_at), $ticket->first_response_at === null ? null : CarbonImmutable::instance($ticket->first_response_at)])->values()->all();
        $resolvedRows = (clone $resolved)->orderByDesc('resolved_at')->limit($cap)->get(['created_at', 'resolved_at'])
            ->map(fn (Ticket $ticket): array => [CarbonImmutable::instance($ticket->created_at), CarbonImmutable::instance($ticket->resolved_at ?? $ticket->created_at)])->values()->all();

        $firstResponses = [];

        foreach ($createdRows as [$at, $responded]) {
            if ($responded !== null) {
                $firstResponses[] = max(0, (int) $at->diffInSeconds($responded));
            }
        }

        $resolutions = array_map(static fn (array $row): int => max(0, (int) $row[0]->diffInSeconds($row[1])), $resolvedRows);
        [$csatCount, $csatAverage, $distribution] = $this->satisfaction($range);
        $byAi = $this->resolvedByAi($range);

        return [
            'received' => $received,
            'resolved' => $resolvedCount,
            'first_response' => Duration::median($firstResponses),
            'resolution' => Duration::median($resolutions),
            'sampled' => $received > $cap || $resolvedCount > $cap,
            'csat_average' => $csatAverage,
            'csat_count' => $csatCount,
            'csat_sent' => CsatResponse::query()->whereBetween('sent_at', [$range->fromUtc(), $range->toUtc()])->count(),
            'csat_distribution' => $distribution,
            'ai_resolved' => $byAi,
            'ai_percent' => $resolvedCount === 0 ? null : (int) round(100 * $byAi / $resolvedCount),
            'created_rows' => $createdRows,
            'resolved_rows' => $resolvedRows,
        ];
    }

    /**
     * @return array{0: int, 1: float|null, 2: array<int, int>}
     */
    private function satisfaction(Range $range): array
    {
        $distribution = [];
        $total = 0;
        $sum = 0;

        foreach (range(1, 5) as $score) {
            $count = CsatResponse::query()->whereBetween('answered_at', [$range->fromUtc(), $range->toUtc()])->where('score', $score)->count();
            $distribution[$score] = $count;
            $total += $count;
            $sum += $count * $score;
        }

        return [$total, $total === 0 ? null : round($sum / $total, 1), $distribution];
    }

    /**
     * Tickets resueltos o cerrados en el rango cuyas respuestas fueron todas de la IA en modo automático: sin mensajes de
     * agentes y sin borradores aprobados por una persona, y sin contar el aviso fijo de paso a una persona.
     */
    private function resolvedByAi(Range $range): int
    {
        return Ticket::query()
            ->whereBetween('resolved_at', [$range->fromUtc(), $range->toUtc()])
            ->whereHas('messages', fn ($query) => $query->where('author', MessageAuthor::Ai->value)->whereNull('user_id')->whereDoesntHave('aiRun', fn ($run) => $run->where('status', 'escalated')))
            ->whereDoesntHave('messages', fn ($query) => $query->where('author', MessageAuthor::Agent->value))
            ->whereDoesntHave('messages', fn ($query) => $query->where('author', MessageAuthor::Ai->value)->whereNotNull('user_id'))
            ->count();
    }

    /**
     * El gasto de IA del rango. El consumo se anota por día UTC, así que se suman los días UTC que toca el rango.
     *
     * @return array{spent_micro: int, spent: string, calls: int, credits: int}
     */
    private function aiUsage(Range $range): array
    {
        $days = [$range->fromUtc()->toDateString(), $range->toUtc()->toDateString()];

        $spent = (int) AiUsageDaily::query()->whereBetween('day', $days)->sum('cost_micro');

        return [
            'spent_micro' => $spent,
            'spent' => Money::usd($spent),
            'calls' => (int) AiUsageDaily::query()->whereBetween('day', $days)->sum('calls'),
            'credits' => AiCall::query()->where('purpose', AiPurpose::Reply->value)->where('status', 'ok')->whereBetween('created_at', [$range->fromUtc(), $range->toUtc()])->count(),
        ];
    }

    /**
     * @param  array<int, array{0: CarbonImmutable, 1: CarbonImmutable|null}>  $created
     * @param  array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $resolved
     * @return array<int, array{date: string, received: int, resolved: int, first_response: int|null}>
     */
    private function trend(Range $range, array $created, array $resolved): array
    {
        $zone = config()->string('metrics.timezone');
        $days = array_fill_keys($range->dates(), ['received' => 0, 'resolved' => 0, 'responses' => []]);

        foreach ($created as [$at, $responded]) {
            $day = $at->setTimezone($zone)->toDateString();

            if (isset($days[$day])) {
                $days[$day]['received']++;

                if ($responded !== null) {
                    $days[$day]['responses'][] = max(0, (int) $at->diffInSeconds($responded));
                }
            }
        }

        foreach ($resolved as [, $at]) {
            $day = $at->setTimezone($zone)->toDateString();

            if (isset($days[$day])) {
                $days[$day]['resolved']++;
            }
        }

        $series = [];

        foreach ($days as $date => $values) {
            $series[] = ['date' => $date, 'received' => $values['received'], 'resolved' => $values['resolved'], 'first_response' => Duration::median($values['responses'])];
        }

        return $series;
    }

    /**
     * @param  'percent'|'points'  $unit  Porcentaje sobre el valor anterior, o diferencia de puntos.
     * @param  'up'|'down'  $better  Hacia dónde mejora la cifra.
     * @return array{value: int|float|null, previous: int|float|null, delta: int|float|null, unit: string, better: string}
     */
    private function card(int|float|null $value, int|float|null $previous, string $unit, string $better): array
    {
        $delta = null;

        if ($value !== null && $previous !== null) {
            $delta = $unit === 'points' ? round($value - $previous, 1) : ($previous === 0 ? null : (int) round(100 * ($value - $previous) / $previous));
        }

        return ['value' => $value, 'previous' => $previous, 'delta' => $delta, 'unit' => $unit, 'better' => $better];
    }
}
