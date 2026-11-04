<?php

declare(strict_types=1);

namespace App\Metrics;

/** Una duración en segundos dicha como la lee una persona: «8 min», «3 h 5 min», «2 d 4 h». */
final class Duration
{
    public static function label(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        $minutes = intdiv(max(0, $seconds), 60);

        return match (true) {
            $minutes < 1 => __('metrics.duration.under_minute'),
            $minutes < 60 => $minutes.' min',
            $minutes < 2880 => intdiv($minutes, 60).' h'.($minutes % 60 === 0 ? '' : ' '.($minutes % 60).' min'),
            default => intdiv($minutes, 1440).' d'.(intdiv($minutes % 1440, 60) === 0 ? '' : ' '.intdiv($minutes % 1440, 60).' h'),
        };
    }

    /**
     * La mediana de una lista de duraciones; nula si no hay ninguna.
     *
     * @param  array<int, int>  $values
     */
    public static function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? $values[$middle] : (int) round(($values[$middle - 1] + $values[$middle]) / 2);
    }
}
