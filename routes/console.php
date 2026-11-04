<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Mantenimiento que antes había que lanzar a mano. Cada tarea corre en un solo servidor y no se pisa a sí misma.
// En producción el planificador es el servicio `scheduler` (php artisan schedule:work); en local no arranca solo.
Schedule::command('attachments:rescan')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('widget:prune')->dailyAt('03:10')->withoutOverlapping()->onOneServer();
Schedule::command('knowledge:reconcile')->dailyAt('03:30')->withoutOverlapping()->onOneServer();
Schedule::command('queue:prune-failed', ['--hours' => 720])->dailyAt('03:50')->withoutOverlapping()->onOneServer();
Schedule::command('auth:clear-resets')->dailyAt('04:00')->withoutOverlapping()->onOneServer();
