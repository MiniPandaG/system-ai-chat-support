<?php

declare(strict_types=1);

namespace App\Providers;

use App\Attachments\ClamAvScanner;
use App\Attachments\FakeScanner;
use App\Attachments\Scanner;
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
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->singleton(TenantJobContext::class);

        $this->app->bind(Scanner::class, function (): Scanner {
            $driver = config()->string('attachments.scanner');

            return match (true) {
                $driver === 'clamav' => new ClamAvScanner(
                    config()->string('attachments.clamav.host'),
                    config()->integer('attachments.clamav.port'),
                    config()->integer('attachments.clamav.timeout'),
                ),
                // El antivirus de mentira da por limpio casi todo: en producción sería una puerta abierta.
                $driver === 'fake' && ! $this->app->isProduction() => new FakeScanner,
                default => throw new LogicException("ATTACHMENT_SCANNER=\"{$driver}\" no es válido en este entorno: usa «clamav»."),
            };
        });
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
