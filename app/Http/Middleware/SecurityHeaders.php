<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
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
            $headers->set('Content-Security-Policy', $this->contentSecurityPolicy($nonce));
        }

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('Cross-Origin-Resource-Policy', 'same-origin');

        if ($request->isSecure() && config()->boolean('security.hsts.enabled')) {
            $maxAge = config()->integer('security.hsts.max_age');
            $headers->set('Strict-Transport-Security', "max-age={$maxAge}; includeSubDomains");
        }

        return $response;
    }

    private function contentSecurityPolicy(string $nonce): string
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
            'connect-src' => ["'self'", ...$httpOrigins, ...$webSockets],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'none'"],
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
