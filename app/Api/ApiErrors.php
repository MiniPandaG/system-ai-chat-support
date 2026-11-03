<?php

declare(strict_types=1);

namespace App\Api;

use App\Plans\LimitReached;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * El formato de error de la API: siempre `{"error": {"code", "message", "details"?}}`. Los códigos son una lista cerrada y
 * los mensajes están en español y no revelan estructura interna, nombres de clases ni rutas; un error del servidor sale
 * como un 500 genérico (el detalle queda solo en el registro del servidor).
 */
final class ApiErrors
{
    public static function render(Throwable $exception): JsonResponse
    {
        if ($exception instanceof ValidationException) {
            return self::json(422, 'validation_failed', __('api.errors.validation_failed'), ['details' => $exception->errors()]);
        }

        if ($exception instanceof LimitReached) {
            return self::json(422, 'plan_limit', $exception->getMessage(), ['limit' => $exception->limit->value]);
        }

        if ($exception instanceof ModelNotFoundException) {
            return self::json(404, 'not_found', __('api.errors.not_found'));
        }

        if ($exception instanceof AuthorizationException) {
            return self::json(403, 'forbidden', __('api.errors.forbidden'));
        }

        if ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();
            $headers = [];

            foreach (['Retry-After', 'WWW-Authenticate', 'Allow'] as $name) {
                $value = $exception->getHeaders()[$name] ?? null;

                if (is_scalar($value) && (string) $value !== '') {
                    $headers[$name] = (string) $value;
                }
            }

            $code = self::codeFor($status);
            $named = $exception->getMessage();

            // Un error con nombre propio (idempotencia, límites) lleva su clave de texto: «api.errors.{código}».
            if (preg_match('/\Aapi\.errors\.([a-z_]+)\z/', $named, $parts) === 1 && Lang::has($named)) {
                $code = $parts[1];
            }

            $message = __('api.errors.'.$code);

            return self::json($status, $code, $message, [], $headers);
        }

        return self::json(500, 'server_error', __('api.errors.server_error'));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, string>  $headers
     */
    private static function json(int $status, string $code, string $message, array $extra = [], array $headers = []): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message, ...$extra]], $status, $headers);
    }

    private static function codeFor(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            409 => 'conflict',
            413 => 'payload_too_large',
            415 => 'unsupported_media_type',
            422 => 'validation_failed',
            429 => 'rate_limited',
            default => $status >= 500 ? 'server_error' : 'bad_request',
        };
    }
}
