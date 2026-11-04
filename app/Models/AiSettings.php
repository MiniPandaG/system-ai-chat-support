<?php

declare(strict_types=1);

namespace App\Models;

use App\Ai\AiChannel;
use App\Ai\AiMode;
use App\Ai\ScheduleMode;
use App\Ai\ToneStyle;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Cómo participa la IA en una empresa. Sin fila, está apagada y todo lo demás vale lo más prudente. Nada es rellenable:
 * lo escribe solo `UpdateAiSettings`, que valida cada valor contra listas y rangos cerrados.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string|null $reply_model
 * @property string|null $summary_model
 * @property AiMode $widget_mode
 * @property AiMode $email_mode
 * @property bool $identify_as_ai
 * @property bool $require_knowledge
 * @property int $min_confidence
 * @property bool $show_sources
 * @property ToneStyle $tone
 * @property string|null $tone_custom
 * @property ScheduleMode $schedule_mode
 * @property list<int>|null $schedule_days
 * @property string $schedule_start
 * @property string $schedule_end
 * @property string $schedule_timezone
 * @property list<string>|null $escalate_topics
 * @property int $max_messages
 * @property int|null $daily_spend_micro
 * @property int|null $monthly_spend_micro
 * @property int|null $updated_by
 */
class AiSettings extends Model
{
    use BelongsToTenant;

    protected $table = 'ai_settings';

    /** Lo que vale cuando la empresa no ha configurado nada: todo apagado y lo más prudente en lo demás. */
    public static function current(): self
    {
        return self::query()->first() ?? (new self)->forceFill(self::defaults());
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'reply_model' => null,
            'summary_model' => null,
            'widget_mode' => AiMode::Off,
            'email_mode' => AiMode::Off,
            'identify_as_ai' => true,
            'require_knowledge' => true,
            'min_confidence' => 70,
            'show_sources' => true,
            'tone' => ToneStyle::Friendly,
            'tone_custom' => null,
            'schedule_mode' => ScheduleMode::Always,
            'schedule_days' => null,
            'schedule_start' => '09:00',
            'schedule_end' => '18:00',
            'schedule_timezone' => 'America/Bogota',
            'escalate_topics' => null,
            'max_messages' => 5,
            'daily_spend_micro' => null,
            'monthly_spend_micro' => null,
        ];
    }

    public function modeFor(AiChannel $channel): AiMode
    {
        return $channel === AiChannel::Widget ? $this->widget_mode : $this->email_mode;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'widget_mode' => AiMode::class,
            'email_mode' => AiMode::class,
            'identify_as_ai' => 'boolean',
            'require_knowledge' => 'boolean',
            'min_confidence' => 'integer',
            'show_sources' => 'boolean',
            'tone' => ToneStyle::class,
            'schedule_mode' => ScheduleMode::class,
            'schedule_days' => 'array',
            'escalate_topics' => 'array',
            'max_messages' => 'integer',
            'daily_spend_micro' => 'integer',
            'monthly_spend_micro' => 'integer',
        ];
    }
}
