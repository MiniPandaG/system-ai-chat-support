<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Cambiar los ajustes de la IA y revisar borradores: por cuenta, con holgura para uso normal.
        RateLimiter::for('ai-settings', fn (Request $request): Limit => Limit::perMinute(30)->by('ai-settings:'.$this->accountKey($request)));
        RateLimiter::for('ai-draft', fn (Request $request): Limit => Limit::perMinute(60)->by('ai-draft:'.$this->accountKey($request)));
    }

    private function accountKey(Request $request): string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : (string) $request->ip();
    }
}
