<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Ai\AiMode;
use App\Ai\AiSettingsInput;
use App\Ai\ModelCatalog;
use App\Ai\ScheduleMode;
use App\Ai\ToneStyle;
use App\Models\AiSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Los ajustes de la IA: listas cerradas (modos, modelos disponibles del catálogo, zonas horarias), números dentro de un
 * rango y textos cortos. Nada más se acepta y todo se limita: el cliente no puede fijar un tope de gasto por encima del de
 * la plataforma ni un nivel de confianza que deje pasar cualquier cosa.
 */
class UpdateAiSettingsRequest extends FormRequest
{
    /** El nivel mínimo de confianza que una empresa puede fijar: más bajo dejaría responder casi cualquier cosa. */
    public const CONFIDENCE_FLOOR = 30;

    public function authorize(): bool
    {
        return $this->user()?->can('manage', AiSettings::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $models = array_map(static fn ($model): string => $model->id, app(ModelCatalog::class)->available());
        $maxDaily = config()->integer('ai.limits.max_daily_spend_micro') / 1_000_000;
        $maxMonthly = config()->integer('ai.limits.max_monthly_spend_micro') / 1_000_000;
        $scheduled = $this->input('schedule_mode') !== ScheduleMode::Always->value;

        return [
            'reply_model' => ['nullable', 'string', Rule::in($models)],
            'summary_model' => ['nullable', 'string', Rule::in($models)],
            'widget_mode' => ['required', Rule::enum(AiMode::class)],
            'email_mode' => ['required', Rule::enum(AiMode::class)],
            'identify_as_ai' => ['required', 'boolean'],
            'require_knowledge' => ['required', 'boolean'],
            'min_confidence' => ['required', 'integer', 'between:'.self::CONFIDENCE_FLOOR.',100'],
            'show_sources' => ['required', 'boolean'],
            'tone' => ['required', Rule::enum(ToneStyle::class)],
            'tone_custom' => [Rule::requiredIf($this->input('tone') === ToneStyle::Custom->value), 'nullable', 'string', 'max:'.config()->integer('ai.escalation.custom_tone_length')],
            'schedule_mode' => ['required', Rule::enum(ScheduleMode::class)],
            'schedule_days' => [Rule::requiredIf($scheduled), 'nullable', 'array', 'max:7'],
            'schedule_days.*' => ['integer', 'between:1,7'],
            'schedule_start' => ['required', 'date_format:H:i'],
            'schedule_end' => ['required', 'date_format:H:i', $scheduled ? 'after:schedule_start' : 'string'],
            'schedule_timezone' => ['required', 'string', Rule::in(config()->array('ai.timezones'))],
            'escalate_topics' => ['nullable', 'array', 'max:'.config()->integer('ai.escalation.max_topics')],
            'escalate_topics.*' => ['nullable', 'string', 'max:'.config()->integer('ai.escalation.topic_length')],
            'max_messages' => ['required', 'integer', 'between:1,20'],
            'daily_spend' => ['nullable', 'numeric', 'min:0.01', 'max:'.$maxDaily],
            'monthly_spend' => ['nullable', 'numeric', 'min:0.01', 'max:'.$maxMonthly],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $daily = $this->input('daily_spend');
                $monthly = $this->input('monthly_spend');

                if (is_numeric($daily) && is_numeric($monthly) && (float) $daily > (float) $monthly) {
                    $validator->errors()->add('daily_spend', __('ai.errors.daily_over_monthly'));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reply_model.in' => __('ai.errors.model'),
            'summary_model.in' => __('ai.errors.model'),
            'widget_mode.required' => __('ai.errors.mode'),
            'widget_mode.enum' => __('ai.errors.mode'),
            'email_mode.required' => __('ai.errors.mode'),
            'email_mode.enum' => __('ai.errors.mode'),
            'min_confidence.between' => __('ai.errors.confidence', ['min' => self::CONFIDENCE_FLOOR]),
            'min_confidence.integer' => __('ai.errors.confidence', ['min' => self::CONFIDENCE_FLOOR]),
            'tone.enum' => __('ai.errors.tone'),
            'tone_custom.required' => __('ai.errors.tone_custom'),
            'tone_custom.max' => __('ai.errors.tone_custom_max', ['max' => config()->integer('ai.escalation.custom_tone_length')]),
            'schedule_mode.enum' => __('ai.errors.schedule'),
            'schedule_days.required' => __('ai.errors.schedule_days'),
            'schedule_days.*.between' => __('ai.errors.schedule_days'),
            'schedule_start.date_format' => __('ai.errors.time'),
            'schedule_end.date_format' => __('ai.errors.time'),
            'schedule_end.after' => __('ai.errors.time_order'),
            'schedule_timezone.in' => __('ai.errors.timezone'),
            'escalate_topics.max' => __('ai.errors.topics', ['max' => config()->integer('ai.escalation.max_topics')]),
            'escalate_topics.*.max' => __('ai.errors.topic_length', ['max' => config()->integer('ai.escalation.topic_length')]),
            'max_messages.between' => __('ai.errors.max_messages'),
            'daily_spend.min' => __('ai.errors.spend'),
            'daily_spend.max' => __('ai.errors.spend_max', ['max' => number_format(config()->integer('ai.limits.max_daily_spend_micro') / 1_000_000, 0, ',', '.')]),
            'monthly_spend.min' => __('ai.errors.spend'),
            'monthly_spend.max' => __('ai.errors.spend_max', ['max' => number_format(config()->integer('ai.limits.max_monthly_spend_micro') / 1_000_000, 0, ',', '.')]),
        ];
    }

    public function toInput(): AiSettingsInput
    {
        $days = array_values(array_map('intval', array_filter((array) $this->input('schedule_days', []), 'is_numeric')));
        $days = array_values(array_unique($days));
        sort($days);

        $topics = [];

        foreach ((array) $this->input('escalate_topics', []) as $topic) {
            $clean = is_string($topic) ? trim(preg_replace('/\s+/u', ' ', $topic) ?? '') : '';

            if ($clean !== '' && ! in_array(mb_strtolower($clean), array_map('mb_strtolower', $topics), true)) {
                $topics[] = $clean;
            }
        }

        return new AiSettingsInput(
            $this->modelId('reply_model'),
            $this->modelId('summary_model'),
            AiMode::from($this->string('widget_mode')->toString()),
            AiMode::from($this->string('email_mode')->toString()),
            $this->boolean('identify_as_ai'),
            $this->boolean('require_knowledge'),
            $this->integer('min_confidence'),
            $this->boolean('show_sources'),
            ToneStyle::from($this->string('tone')->toString()),
            $this->filled('tone_custom') ? trim($this->string('tone_custom')->toString()) : null,
            ScheduleMode::from($this->string('schedule_mode')->toString()),
            $days,
            $this->string('schedule_start')->toString(),
            $this->string('schedule_end')->toString(),
            $this->string('schedule_timezone')->toString(),
            $topics,
            $this->integer('max_messages'),
            $this->micro('daily_spend'),
            $this->micro('monthly_spend'),
        );
    }

    private function modelId(string $field): ?string
    {
        $model = $this->input($field);

        return is_string($model) && $model !== '' ? $model : null;
    }

    private function micro(string $field): ?int
    {
        $value = $this->input($field);

        return is_numeric($value) ? (int) round((float) $value * 1_000_000) : null;
    }
}
