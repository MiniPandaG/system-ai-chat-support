<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\UpdateAiSettings;
use App\Ai\AiBudget;
use App\Ai\CatalogModel;
use App\Ai\ModelCatalog;
use App\Http\Requests\UpdateAiSettingsRequest;
use App\Models\AiSettings;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cómo participa la IA en la empresa: en qué canales, con qué modelo y con qué reglas, y cuánto lleva gastado frente a sus
 * topes. Solo administradores (AiSettingsPolicy). Se muestra el costo estimado y dónde procesa cada modelo para que la
 * elección sea informada.
 */
class AiSettingsController extends Controller
{
    public function show(ModelCatalog $catalog, AiBudget $budget, TenantContext $context): Response
    {
        Gate::authorize('manage', AiSettings::class);
        $settings = AiSettings::current();
        $tenant = $context->current();
        abort_if($tenant === null, 404);

        $usage = $budget->snapshot($settings, $tenant);

        return Inertia::render('settings/Ai', [
            'copy' => __('ai.page'),
            'settings' => [
                'reply_model' => $settings->reply_model,
                'summary_model' => $settings->summary_model,
                'widget_mode' => $settings->widget_mode->value,
                'email_mode' => $settings->email_mode->value,
                'identify_as_ai' => $settings->identify_as_ai,
                'require_knowledge' => $settings->require_knowledge,
                'min_confidence' => $settings->min_confidence,
                'show_sources' => $settings->show_sources,
                'tone' => $settings->tone->value,
                'tone_custom' => $settings->tone_custom ?? '',
                'schedule_mode' => $settings->schedule_mode->value,
                'schedule_days' => $settings->schedule_days ?? [1, 2, 3, 4, 5],
                'schedule_start' => $settings->schedule_start,
                'schedule_end' => $settings->schedule_end,
                'schedule_timezone' => $settings->schedule_timezone,
                'escalate_topics' => $settings->escalate_topics ?? [],
                'max_messages' => $settings->max_messages,
                'daily_spend' => $settings->daily_spend_micro === null ? '' : $this->usd($settings->daily_spend_micro),
                'monthly_spend' => $settings->monthly_spend_micro === null ? '' : $this->usd($settings->monthly_spend_micro),
            ],
            'defaults' => [
                'daily_spend' => $this->usd(config()->integer('ai.limits.daily_spend_micro')),
                'monthly_spend' => $this->usd(config()->integer('ai.limits.monthly_spend_micro')),
                'max_daily_spend' => $this->usd(config()->integer('ai.limits.max_daily_spend_micro')),
                'max_monthly_spend' => $this->usd(config()->integer('ai.limits.max_monthly_spend_micro')),
                'min_confidence' => UpdateAiSettingsRequest::CONFIDENCE_FLOOR,
                'max_topics' => config()->integer('ai.escalation.max_topics'),
                'topic_length' => config()->integer('ai.escalation.topic_length'),
                'tone_length' => config()->integer('ai.escalation.custom_tone_length'),
            ],
            'timezones' => config()->array('ai.timezones'),
            'models' => array_map(fn (CatalogModel $model): array => [
                'id' => $model->id,
                'label' => $model->label,
                'tier' => $model->tier,
                'provider' => $model->providerLabel,
                'region' => $model->region,
                'cost' => $this->cost($catalog->estimate($model)),
            ], $catalog->available()),
            'usage' => [
                'paused' => $usage['paused'] === null ? null : __('ai.reasons.'.$usage['paused']),
                'day' => $this->meter($usage['day']['spent'], $usage['day']['limit']),
                'month' => $this->meter($usage['month']['spent'], $usage['month']['limit']),
                'credits' => $usage['credits']['limit'] === null ? null : ['used' => $usage['credits']['used'], 'limit' => $usage['credits']['limit'], 'percent' => $this->percent($usage['credits']['used'], $usage['credits']['limit'])],
            ],
        ]);
    }

    public function update(UpdateAiSettingsRequest $request, UpdateAiSettings $update): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $update($user, $request->toInput());

        return back()->with('status', __('ai.page.saved'));
    }

    /**
     * @return array{spent: string, limit: string, percent: int}
     */
    private function meter(int $spent, int $limit): array
    {
        return ['spent' => $this->cost($spent), 'limit' => $this->cost($limit), 'percent' => $this->percent($spent, $limit)];
    }

    private function percent(int $used, int $limit): int
    {
        return $limit <= 0 ? 100 : min(100, intdiv($used * 100, $limit));
    }

    /** Millonésimas de dólar a «US$ 0,0004»: con más decimales cuando es muy poco. */
    private function cost(int $micro): string
    {
        return 'US$ '.number_format($micro / 1_000_000, $micro < 1000 ? 5 : ($micro < 100_000 ? 4 : 2), ',', '.');
    }

    /** Dólares como número con punto decimal para un campo de formulario. */
    private function usd(int $micro): string
    {
        return rtrim(rtrim(number_format($micro / 1_000_000, 2, '.', ''), '0'), '.');
    }
}
