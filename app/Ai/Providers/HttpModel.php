<?php

declare(strict_types=1);

namespace App\Ai\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Lo común de los proveedores por HTTP: la dirección sale de la configuración, no se siguen redirecciones, hay tiempo y
 * tamaño máximos, y todo fallo se reduce a un motivo de la lista cerrada. Nunca se devuelve ni se registra el cuerpo de una
 * respuesta con error ni la llave.
 */
abstract class HttpModel implements LanguageModel
{
    public function __construct(
        protected readonly Factory $http,
        protected readonly string $baseUrl,
        protected readonly string $apiKey,
        protected readonly int $connectTimeout,
        protected readonly int $timeout,
        protected readonly int $maxResponseBytes,
    ) {}

    /**
     * @param  array<string, string>  $headers
     * @param  array<int<0, max>|string, mixed>  $body
     *
     * @throws ProviderUnavailable
     */
    protected function post(string $path, array $headers, array $body): Response
    {
        if ($this->apiKey === '') {
            throw new ProviderUnavailable(ProviderError::NotConfigured);
        }

        try {
            $response = $this->client($headers)->post(rtrim($this->baseUrl, '/').$path, $body);
        } catch (ProviderUnavailable $error) {
            throw $error;
        } catch (ConnectionException $error) {
            throw new ProviderUnavailable(str_contains(strtolower($error->getMessage()), 'timed out') ? ProviderError::Timeout : ProviderError::Connection);
        } catch (Throwable) {
            throw new ProviderUnavailable(ProviderError::Connection);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new ProviderUnavailable(ProviderError::Auth);
        }

        if ($response->status() === 429) {
            throw new ProviderUnavailable(ProviderError::RateLimited);
        }

        if ($response->status() >= 500) {
            throw new ProviderUnavailable(ProviderError::Server);
        }

        if (! $response->successful()) {
            throw new ProviderUnavailable(ProviderError::Rejected);
        }

        if (strlen($response->body()) > $this->maxResponseBytes) {
            throw new ProviderUnavailable(ProviderError::TooLarge);
        }

        return $response;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function client(array $headers): PendingRequest
    {
        $max = $this->maxResponseBytes;

        return $this->http
            ->withHeaders($headers)
            ->acceptJson()
            ->asJson()
            ->connectTimeout($this->connectTimeout)
            ->timeout($this->timeout)
            ->withoutRedirecting()
            ->withOptions(['on_headers' => static function (ResponseInterface $response) use ($max): void {
                // Una respuesta que ya avisa de ser enorme se corta antes de descargarla.
                if ((int) $response->getHeaderLine('Content-Length') > $max) {
                    throw new ProviderUnavailable(ProviderError::TooLarge);
                }
            }]);
    }

    /** Un número entero de tokens del uso que informa el proveedor; lo ausente o raro cuenta como cero. */
    protected static function tokens(mixed $value): int
    {
        return is_int($value) && $value > 0 ? $value : 0;
    }
}
