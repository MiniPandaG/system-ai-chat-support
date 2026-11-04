<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

/** @return array<string, Event> */
function scheduledEvents(): array
{
    $events = [];

    foreach (app(Schedule::class)->events() as $event) {
        $events[trim((string) preg_replace('/^.*artisan[\'"]?\s+/', '', $event->command ?? ''))] = $event;
    }

    return $events;
}

it('el mantenimiento que los avisos de M5 a M10 dejaron sin programar ya está programado', function (): void {
    $commands = array_keys(scheduledEvents());

    expect($commands)->toContain('attachments:rescan')
        ->toContain('widget:prune')
        ->toContain('knowledge:reconcile');
});

it('cada tarea programada es un comando que existe, corre en un solo servidor y no se pisa a sí misma', function (): void {
    $known = array_keys(Artisan::all());

    foreach (scheduledEvents() as $name => $event) {
        $base = explode(' ', $name)[0];

        expect($known)->toContain($base)
            ->and($event->onOneServer)->toBeTrue("{$name} debe correr en un solo servidor")
            ->and($event->withoutOverlapping)->toBeTrue("{$name} no debe pisarse a sí misma");
    }
});

it('la limpieza de trabajos fallidos conserva un mes y la de enlaces de recuperación está programada', function (): void {
    $commands = array_keys(scheduledEvents());

    expect($commands)->toContain('queue:prune-failed --hours=720')->toContain('auth:clear-resets');
});
