<?php

declare(strict_types=1);

namespace App\Providers;

use App\Branding\Palette;
use App\Models\TenantBranding;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantJobContext;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->singleton(TenantJobContext::class);
    }

    public function boot(): void
    {
        $this->app->make(TenantJobContext::class)->register();

        // Paleta de la empresa en la primera carga de la página, para que no parpadee el naranja de fábrica.
        View::composer('app', function (ViewContract $view): void {
            $branding = app(TenantContext::class)->id() === null ? null : TenantBranding::query()->first();

            $view->with('brandingCss', Palette::css($branding?->panelVariables() ?? []));
        });

        // Un miembro solo se resuelve dentro de la empresa en contexto: otra empresa recibe 404, no 403.
        Route::bind('member', function (string $value): User {
            $tenantId = app(TenantContext::class)->id();
            abort_if($tenantId === null || ! ctype_digit($value), 404);

            return User::query()->inTenant($tenantId)->findOrFail((int) $value);
        });

        Model::shouldBeStrict(! $this->app->isProduction());
        DB::prohibitDestructiveCommands($this->app->isProduction());

        if ($this->app->isProduction()) {
            URL::forceHttps();
        }
    }
}
