<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\ApiKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Los límites de peticiones de la API y de la pantalla de llaves (RS-07).
 */
class ApiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Todas las peticiones de una llave y de una dirección. Pasados los topes: 429 con el formato de error de la API.
        RateLimiter::for('api', function (Request $request): array {
            $limits = config()->array('api.limits');
            $key = $request->attributes->get('api_key');

            return [
                Limit::perMinute(self::limit($limits, 'per_key_per_minute'))->by('api-key:'.($key instanceof ApiKey ? $key->id : (string) $request->ip())),
                Limit::perMinute(self::limit($limits, 'per_ip_per_minute'))->by('api-ip:'.$request->ip()),
            ];
        });

        // La especificación pública: por dirección.
        RateLimiter::for('api-docs', fn (Request $request): Limit => Limit::perMinute(60)->by('api-docs:'.$request->ip()));

        // Crear y modificar pesa más que leer: un tope propio por llave.
        RateLimiter::for('api-writes', function (Request $request): Limit {
            $key = $request->attributes->get('api_key');

            return Limit::perMinute(self::limit(config()->array('api.limits'), 'writes_per_key_per_minute'))->by('api-writes:'.($key instanceof ApiKey ? $key->id : (string) $request->ip()));
        });

        // Crear, rotar y revocar llaves desde el panel: por cuenta.
        RateLimiter::for('api-keys-manage', function (Request $request): Limit {
            $id = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(20)->by('api-keys-manage:'.(is_scalar($id) ? (string) $id : (string) $request->ip()));
        });
    }

    /**
     * @param  array<int|string, mixed>  $limits
     */
    private static function limit(array $limits, string $name): int
    {
        $value = $limits[$name] ?? 0;

        return is_int($value) ? $value : 0;
    }
}
