<?php

declare(strict_types=1);

namespace App\Ai\Rules;

use App\Ai\ScheduleMode;
use App\Models\AiSettings;
use DateTimeZone;
use Illuminate\Support\Carbon;

/**
 * Cuándo puede participar la IA (switch «Horario de la IA»). La regla la aplica el código: la IA no decide si es hora de
 * responder. El horario es de días de la semana (1 = lunes a 7 = domingo) y una franja del día en la zona de la empresa.
 */
class Schedule
{
    public function allows(AiSettings $settings, ?Carbon $at = null): bool
    {
        return match ($settings->schedule_mode) {
            ScheduleMode::Always => true,
            ScheduleMode::Within => $this->inside($settings, $at ?? now()),
            ScheduleMode::Outside => ! $this->inside($settings, $at ?? now()),
        };
    }

    private function inside(AiSettings $settings, Carbon $at): bool
    {
        $zone = in_array($settings->schedule_timezone, DateTimeZone::listIdentifiers(), true) ? $settings->schedule_timezone : 'UTC';
        $local = $at->copy()->setTimezone($zone);

        if (! in_array($local->dayOfWeekIso, $settings->schedule_days ?? [], true)) {
            return false;
        }

        $now = $local->format('H:i');

        return $now >= $settings->schedule_start && $now < $settings->schedule_end;
    }
}
