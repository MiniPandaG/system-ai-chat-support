<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\IdempotencyRecord;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Hace seguras de repetir las creaciones de la API. Con la cabecera `Idempotency-Key` la primera petición se ejecuta y su
 * respuesta se recuerda 24 horas; repetirla con la misma clave y el mismo contenido devuelve esa respuesta (con la cabecera
 * `Idempotent-Replayed`) y no crea nada. La misma clave con otro contenido es un error, y una petición igual mientras la
 * primera sigue en curso responde 409. Solo se recuerdan los resultados correctos (2xx): un error se puede corregir y
 * reintentar con la misma clave. La clave se acota a cada llave: una llave no ve ni choca con las de otra.
 */
class IdempotentRequests
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Idempotency-Key');

        if ($header === null) {
            return $next($request);
        }

        $key = $request->attributes->get('api_key');

        abort_unless($key instanceof ApiKey, 401);
        abort_unless(is_string($header) && preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $header) === 1, 422, 'api.errors.idempotency_key_invalid');

        $keyHash = hash('sha256', $header);
        $requestHash = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());

        IdempotencyRecord::query()->where('api_key_id', $key->id)->where('expires_at', '<=', now())->delete();

        $existing = IdempotencyRecord::query()->where('api_key_id', $key->id)->where('key_hash', $keyHash)->first();

        if ($existing !== null) {
            return $this->answerExisting($existing, $requestHash);
        }

        $record = new IdempotencyRecord;
        $record->forceFill([
            'api_key_id' => $key->id,
            'key_hash' => $keyHash,
            'request_hash' => $requestHash,
            'created_at' => now(),
            'expires_at' => now()->addHours(config()->integer('api.idempotency.ttl_hours')),
        ]);

        try {
            $record->save();
        } catch (UniqueConstraintViolationException) {
            // Otra petición con la misma clave llegó a la vez y se adelantó.
            abort(409, 'api.errors.idempotency_in_progress');
        }

        try {
            $response = $next($request);
        } catch (Throwable $failure) {
            $record->delete();

            throw $failure;
        }

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $record->forceFill([
                'status_code' => $response->getStatusCode(),
                'response_body' => (string) $response->getContent(),
                'location' => $response->headers->get('Location'),
            ])->save();
        } else {
            $record->delete();
        }

        return $response;
    }

    private function answerExisting(IdempotencyRecord $existing, string $requestHash): Response
    {
        if (! hash_equals($existing->request_hash, $requestHash)) {
            abort(422, 'api.errors.idempotency_conflict');
        }

        if ($existing->status_code === null) {
            abort(409, 'api.errors.idempotency_in_progress');
        }

        $response = new JsonResponse(null, $existing->status_code, array_filter(['Location' => $existing->location, 'Idempotent-Replayed' => 'true']));
        $response->setContent((string) $existing->response_body);

        return $response;
    }
}
