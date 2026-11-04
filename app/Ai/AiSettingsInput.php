<?php

declare(strict_types=1);

namespace App\Ai;

/** Los ajustes de la IA que una persona envía, ya validados por `UpdateAiSettingsRequest`. */
final readonly class AiSettingsInput
{
    /**
     * @param  list<int>  $scheduleDays  Días de la semana (1 = lunes a 7 = domingo).
     * @param  list<string>  $topics
     */
    public function __construct(
        public ?string $replyModel,
        public ?string $summaryModel,
        public AiMode $widgetMode,
        public AiMode $emailMode,
        public bool $identifyAsAi,
        public bool $requireKnowledge,
        public int $minConfidence,
        public bool $showSources,
        public ToneStyle $tone,
        public ?string $toneCustom,
        public ScheduleMode $scheduleMode,
        public array $scheduleDays,
        public string $scheduleStart,
        public string $scheduleEnd,
        public string $scheduleTimezone,
        public array $topics,
        public int $maxMessages,
        public ?int $dailySpendMicro,
        public ?int $monthlySpendMicro,
    ) {}
}
