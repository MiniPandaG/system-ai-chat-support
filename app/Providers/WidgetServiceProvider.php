<?php

declare(strict_types=1);

namespace App\Providers;

use App\Widget\SessionToken;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Los límites de peticiones del widget (RS-07). La API es pública, así que cada ruta limita por sesión (la huella del
 * token, sin consultar la base) y por IP, y la apertura de conversaciones nuevas además por llave de empresa.
 */
class WidgetServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('widget-message', function (Request $request): array {
            $limits = config()->array('widget.limits');
            $token = SessionToken::parse($request->bearerToken());

            if ($token !== null) {
                return $this->limited([
                    Limit::perMinute($this->limit($limits, 'messages_per_session_per_minute'))->by('widget-message-session:'.$token['hash']),
                    Limit::perMinute($this->limit($limits, 'messages_per_ip_per_minute'))->by('widget-message-ip:'.$request->ip()),
                ]);
            }

            $key = $request->input('key');

            return $this->limited([
                Limit::perHour($this->limit($limits, 'start_per_ip_per_hour'))->by('widget-start-ip:'.$request->ip()),
                Limit::perHour($this->limit($limits, 'start_per_key_per_hour'))->by('widget-start-key:'.(is_string($key) ? substr($key, 0, 32) : '')),
            ]);
        });

        // Agregar o quitar sitios permitidos desde el panel: por cuenta.
        RateLimiter::for('widget-settings', fn (Request $request): Limit => Limit::perMinute(30)->by('widget-settings:'.$this->accountKey($request)));

        // Cargar el marco no crea nada, pero cada carga consulta la empresa: se limita por IP.
        RateLimiter::for('widget-frame', fn (Request $request): array => $this->limited([
            Limit::perMinute($this->limit(config()->array('widget.limits'), 'frames_per_ip_per_minute'))->by('widget-frame-ip:'.$request->ip()),
        ]));

        RateLimiter::for('widget-read', fn (Request $request): array => $this->bySession($request, 'reads_per_session_per_minute', 'widget-read', 60));
        RateLimiter::for('widget-auth', fn (Request $request): array => $this->bySession($request, 'auth_per_session_per_minute', 'widget-auth', 60));
        RateLimiter::for('widget-survey', fn (Request $request): array => $this->bySession($request, 'survey_per_session_per_hour', 'widget-survey', 3600));
        RateLimiter::for('widget-contact', fn (Request $request): array => $this->bySession($request, 'contact_per_session_per_hour', 'widget-contact', 3600));
    }

    /** La cuenta que hace la petición (o, sin ella, su dirección) como parte de la clave de un límite. */
    private function accountKey(Request $request): string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_scalar($id) ? (string) $id : (string) $request->ip();
    }

    /**
     * @return array<int, Limit>
     */
    private function bySession(Request $request, string $setting, string $name, int $seconds): array
    {
        $token = SessionToken::parse($request->bearerToken());
        $max = $this->limit(config()->array('widget.limits'), $setting);

        return $this->limited([
            Limit::perSecond($max, $seconds)->by($name.'-session:'.($token['hash'] ?? $request->ip())),
        ]);
    }

    /**
     * @param  array<int|string, mixed>  $limits
     */
    private function limit(array $limits, string $name): int
    {
        $value = $limits[$name] ?? 0;

        return is_int($value) ? $value : 0;
    }

    /**
     * Responde al límite con un mensaje en español para quien escribe (la respuesta por defecto está en inglés).
     *
     * @param  list<Limit>  $limits
     * @return array<int, Limit>
     */
    private function limited(array $limits): array
    {
        return array_map(
            fn (Limit $limit): Limit => $limit->response(fn () => response()->json(['message' => __('widget.errors.throttled')], 429)),
            $limits,
        );
    }
}
