<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

/** Las listas de la API se paginan por cursor: el identificador del último elemento de la página, o nulo en la última. */
trait Paginates
{
    /**
     * Se pidió una fila de más para saber si hay otra página.
     *
     * @template T of Model
     *
     * @param  list<T>  $rows
     * @param  callable(T): array<string, mixed>  $present
     */
    private function page(array $rows, int $size, callable $present): JsonResponse
    {
        $more = count($rows) > $size;
        $shown = array_slice($rows, 0, $size);
        $last = $shown === [] ? null : $shown[array_key_last($shown)];
        $cursor = $more && $last !== null ? $last->getKey() : null;

        return response()->json([
            'data' => array_map($present, $shown),
            'next_cursor' => is_int($cursor) ? $cursor : null,
        ]);
    }
}
