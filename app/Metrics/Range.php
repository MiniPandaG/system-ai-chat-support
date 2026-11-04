<?php

declare(strict_types=1);

namespace App\Metrics;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * El rango de fechas de una pantalla de métricas: días completos en la zona horaria de la plataforma, del primero a
 * las 00:00 al último hasta las 23:59:59. Se consulta siempre en UTC. Nunca es más largo que el máximo configurado ni llega
 * al futuro, y se puede pedir el rango anterior del mismo largo para comparar.
 */
final readonly class Range
{
    private function __construct(public CarbonImmutable $from, public CarbonImmutable $to) {}

    /**
     * @throws InvalidArgumentException si el rango no es válido
     */
    public static function make(string $preset, ?string $from = null, ?string $to = null): self
    {
        $zone = config()->string('metrics.timezone');
        $today = CarbonImmutable::now($zone)->startOfDay();

        [$start, $end] = match ($preset) {
            'today' => [$today, $today],
            '7d' => [$today->subDays(6), $today],
            '30d' => [$today->subDays(29), $today],
            'custom' => [self::day($from, $zone), self::day($to, $zone)],
            default => throw new InvalidArgumentException('Rango desconocido.'),
        };

        if ($start->greaterThan($end) || $end->greaterThan($today) || $start->diffInDays($end) + 1 > config()->integer('metrics.max_range_days')) {
            throw new InvalidArgumentException('Rango fuera de los límites.');
        }

        return new self($start, $end->endOfDay());
    }

    /** El rango inmediatamente anterior, con los mismos días. */
    public function previous(): self
    {
        $days = $this->days();

        return new self($this->from->subDays($days), $this->from->subSecond());
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to->startOfDay()) + 1;
    }

    public function fromUtc(): CarbonImmutable
    {
        return $this->from->utc();
    }

    public function toUtc(): CarbonImmutable
    {
        return $this->to->utc();
    }

    /**
     * Cada día del rango como «AAAA-MM-DD» en la zona de la plataforma.
     *
     * @return list<string>
     */
    public function dates(): array
    {
        $dates = [];

        for ($day = $this->from; $day->lessThanOrEqualTo($this->to); $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }

    private static function day(?string $value, string $zone): CarbonImmutable
    {
        if ($value === null || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            throw new InvalidArgumentException('Fecha inválida.');
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $value, $zone);

        // «2026-02-30» no existe: createFromFormat lo corre al 2 de marzo, así que se compara con lo que se escribió.
        if ($parsed === null || $parsed->toDateString() !== $value) {
            throw new InvalidArgumentException('Fecha inválida.');
        }

        return $parsed;
    }
}
