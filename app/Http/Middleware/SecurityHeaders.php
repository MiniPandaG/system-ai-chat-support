<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /** Atributo de la petición con los orígenes que pueden enmarcar la respuesta; solo lo fija el marco del widget. */
    public const FRAME_ANCESTORS = 'frame_ancestors';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(random_bytes(16));
        Vite::useCspNonce($nonce);

        /** @var Response $response */
        $response = $next($request);

        $headers = $response->headers;
        // Una respuesta que ya trae su propia política (la descarga de un adjunto la lleva más estricta) la conserva.
        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', $this->contentSecurityPolicy($nonce, $request));
        }

        $headers->set('X-Content-Type-Options', 'nosniff');
        // Una respuesta con un enlace secreto en la dirección (la encuesta) fija la suya: no mandar ni el origen.
        if (! $headers->has('Referrer-Policy')) {
            $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        // Solo el marco del widget puede incrustarse, y solo en los sitios que la empresa permite (frame-ancestors, en la
        // política de contenido). Todo lo demás sigue sin poder enmarcarse.
        if (is_array($request->attributes->get(self::FRAME_ANCESTORS))) {
            $headers->remove('X-Frame-Options');
            $headers->set('Cross-Origin-Resource-Policy', 'cross-origin');
        } else {
            $headers->set('X-Frame-Options', 'DENY');
            $headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        }

        if ($request->isSecure() && config()->boolean('security.hsts.enabled')) {
            $maxAge = config()->integer('security.hsts.max_age');
            $headers->set('Strict-Transport-Security', "max-age={$maxAge}; includeSubDomains");
        }

        return $response;
    }

    private function contentSecurityPolicy(string $nonce, Request $request): string
    {
        $viteDev = $this->viteDevOrigins();
        $webSockets = array_values(array_filter($viteDev, static fn (string $origin): bool => str_starts_with($origin, 'ws:')));
        $httpOrigins = array_values(array_diff($viteDev, $webSockets));

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-{$nonce}'", ...$httpOrigins],
            'style-src' => ["'self'", "'nonce-{$nonce}'", ...$httpOrigins],
            'img-src' => ["'self'", 'data:'],
            'font-src' => ["'self'", ...$httpOrigins],
            // El tiempo real se conecta por WebSocket al mismo sitio. «'self'» ya lo cubre en los navegadores recientes; el origen
            // ws/wss explícito cubre a los que aún no lo entienden.
            'connect-src' => ["'self'", $this->webSocketOrigin($request), ...$httpOrigins, ...$webSockets],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => $this->frameAncestors($request),
        ];

        if (app()->isProduction()) {
            $directives['upgrade-insecure-requests'] = [];
        }

        return implode('; ', array_map(
            static fn (string $name, array $sources): string => trim($name.' '.implode(' ', $sources)),
            array_keys($directives),
            $directives,
        ));
    }

    /**
     * Quién puede enmarcar la respuesta: nadie, salvo el marco del widget, que declara los sitios de la empresa. Sin ningún
     * sitio permitido sigue siendo nadie.
     *
     * @return list<string>
     */
    private function frameAncestors(Request $request): array
    {
        $allowed = $request->attributes->get(self::FRAME_ANCESTORS);

        if (! is_array($allowed)) {
            return ["'none'"];
        }

        $origins = array_values(array_filter($allowed, static fn (mixed $origin): bool => is_string($origin) && $origin !== ''));

        return $origins === [] ? ["'none'"] : $origins;
    }

    /**
     * El mismo sitio con el esquema de WebSocket: wss si la página es https y siempre en producción, aunque un proxy mal
     * configurado haga que la petición parezca http (nunca se abre un ws:// sin cifrar fuera de local y pruebas).
     */
    private function webSocketOrigin(Request $request): string
    {
        return ($request->isSecure() || app()->environment('production') ? 'wss://' : 'ws://').$request->getHttpHost();
    }

    /**
     * @return list<string>
     */
    private function viteDevOrigins(): array
    {
        if (! app()->environment('local') || ! is_file(public_path('hot'))) {
            return [];
        }

        /** @var list<string> */
        return config()->array('security.vite_dev_origins');
    }
}
