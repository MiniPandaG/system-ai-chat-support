<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class CsatServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('metrics', function (Request $request): Limit {
            $id = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(60)->by('metrics:'.(is_scalar($id) ? (string) $id : (string) $request->ip()));
        });

        // La encuesta es pública: se limita por dirección, y las respuestas (que consumen el enlace) más que las lecturas.
        RateLimiter::for('survey-show', fn (Request $request): Limit => Limit::perMinute(config()->integer('csat.limits.show_per_ip_per_minute'))->by('survey-show:'.$request->ip()));
        RateLimiter::for('survey-answer', fn (Request $request): Limit => Limit::perMinute(config()->integer('csat.limits.answer_per_ip_per_minute'))->by('survey-answer:'.$request->ip()));
    }
}
