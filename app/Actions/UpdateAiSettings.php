<?php

declare(strict_types=1);

namespace App\Actions;

use App\Ai\AiSettingsInput;
use App\Ai\ToneStyle;
use App\Enums\AuditEvent;
use App\Models\AiSettings;
use App\Models\User;
use App\Services\AuditLogger;

/**
 * Cambia cómo participa la IA en la empresa. No hace nada, ni audita, si no hay cambios. Los valores ya vienen validados
 * contra listas y rangos cerrados (modelos del catálogo, modos, horarios, topes). La auditoría guarda lo configurado, que
 * son opciones y números; nunca el texto de los temas ni del tono, que lo escribe la empresa.
 */
class UpdateAiSettings
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function __invoke(User $actor, AiSettingsInput $input): void
    {
        $settings = AiSettings::query()->first() ?? new AiSettings;
        $settings->forceFill([
            'reply_model' => $input->replyModel,
            'summary_model' => $input->summaryModel,
            'widget_mode' => $input->widgetMode,
            'email_mode' => $input->emailMode,
            'identify_as_ai' => $input->identifyAsAi,
            'require_knowledge' => $input->requireKnowledge,
            'min_confidence' => $input->minConfidence,
            'show_sources' => $input->showSources,
            'tone' => $input->tone,
            'tone_custom' => $input->tone === ToneStyle::Custom ? $input->toneCustom : null,
            'schedule_mode' => $input->scheduleMode,
            'schedule_days' => $input->scheduleDays === [] ? null : $input->scheduleDays,
            'schedule_start' => $input->scheduleStart,
            'schedule_end' => $input->scheduleEnd,
            'schedule_timezone' => $input->scheduleTimezone,
            'escalate_topics' => $input->topics === [] ? null : $input->topics,
            'max_messages' => $input->maxMessages,
            'daily_spend_micro' => $input->dailySpendMicro,
            'monthly_spend_micro' => $input->monthlySpendMicro,
        ]);

        if ($settings->exists && ! $settings->isDirty()) {
            return;
        }

        $settings->forceFill(['updated_by' => $actor->id])->save();

        $this->audit->record(AuditEvent::AiSettingsChanged, $actor->id, [
            'model' => $input->replyModel,
            'summary_model' => $input->summaryModel,
            'widget' => $input->widgetMode->value,
            'mail' => $input->emailMode->value,
            'identify' => $input->identifyAsAi,
            'require_knowledge' => $input->requireKnowledge,
            'min_confidence' => $input->minConfidence,
            'show_sources' => $input->showSources,
            'tone' => $input->tone->value,
            'schedule' => $input->scheduleMode->value,
            'topics' => count($input->topics),
            'max_messages' => $input->maxMessages,
            'daily_spend_micro' => $input->dailySpendMicro,
            'monthly_spend_micro' => $input->monthlySpendMicro,
        ]);
    }
}
