<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\MetricsRequest;
use App\Metrics\Range;
use App\Metrics\SupportMetrics;
use App\Models\CsatResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Las métricas del soporte y la satisfacción de los clientes (RF-37, HU-12). Solo administradores (CsatResponsePolicy). Todo
 * se calcula en el servidor con el filtro automático de empresa; el navegador solo elige el rango y el puntaje a filtrar.
 * Un rango inválido no rompe la pantalla: se avisa y se muestra el de 30 días.
 */
class MetricsController extends Controller
{
    public function show(MetricsRequest $request, SupportMetrics $metrics): Response
    {
        Gate::authorize('viewAny', CsatResponse::class);

        [$range, $preset, $invalid] = $this->resolve($request);
        $report = $metrics->report($range);
        $comments = $metrics->comments($range, $request->score())->withQueryString();

        return Inertia::render('metrics/Index', [
            'copy' => __('metrics.page'),
            'range_error' => $invalid ? __('metrics.errors.range', ['max' => config()->integer('metrics.max_range_days')]) : null,
            'filters' => ['range' => $preset, 'from' => $range->from->toDateString(), 'to' => $range->to->toDateString(), 'score' => $request->score()],
            'report' => $report,
            'comments' => [
                'data' => array_values(array_map(fn (CsatResponse $row): array => [
                    'id' => $row->id,
                    'score' => (int) $row->score,
                    'comment' => (string) $row->comment,
                    'answered_label' => $row->answered_at?->setTimezone(config()->string('metrics.timezone'))->isoFormat('D [de] MMM, h:mm a'),
                    'ticket' => $row->ticket === null ? null : ['id' => $row->ticket->id, 'number' => $row->ticket->number, 'subject' => $row->ticket->subject],
                ], $comments->items())),
                'next_url' => $comments->nextPageUrl(),
            ],
        ]);
    }

    /**
     * @return array{0: Range, 1: string, 2: bool} El rango, su nombre y si lo pedido no valía.
     */
    private function resolve(MetricsRequest $request): array
    {
        try {
            return [$request->range(), $request->preset(), false];
        } catch (InvalidArgumentException) {
            return [Range::make('30d'), '30d', true];
        }
    }
}
