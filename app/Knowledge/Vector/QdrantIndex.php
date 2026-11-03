<?php

declare(strict_types=1);

namespace App\Knowledge\Vector;

use App\Knowledge\SourceKind;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Índice vectorial sobre Qdrant (REST), dentro de la red de Docker. Una colección por empresa (`kb_t{id}`): el aislamiento
 * es por construcción, porque una consulta apunta a la colección de una sola empresa y no existe forma de pedir otra. La
 * dirección y la llave salen de la configuración, no se siguen redirecciones y todo fallo se reduce a `IndexUnavailable`
 * con un mensaje genérico.
 */
final class QdrantIndex implements VectorIndex
{
    private const SCROLL_PAGE = 1000;

    public function __construct(
        private readonly Factory $http,
        private readonly string $url,
        private readonly string $apiKey,
        private readonly int $timeoutSeconds,
    ) {}

    public function ensure(int $tenantId, int $dimensions): void
    {
        $name = self::collection($tenantId);
        $exists = $this->send('GET', "/collections/{$name}/exists");
        $found = $exists->json('result.exists');

        if ($found === true) {
            return;
        }

        $created = $this->send('PUT', "/collections/{$name}", ['vectors' => ['size' => $dimensions, 'distance' => 'Cosine'], 'on_disk_payload' => true], allow: [409]);

        if ($created->status() === 409) {
            return;
        }

        $this->send('PUT', "/collections/{$name}/index", ['field_name' => 'source_id', 'field_schema' => 'integer']);
        $this->send('PUT', "/collections/{$name}/index", ['field_name' => 'kind', 'field_schema' => 'keyword']);
    }

    public function upsert(int $tenantId, array $points): void
    {
        if ($points === []) {
            return;
        }

        $this->send('PUT', '/collections/'.self::collection($tenantId).'/points?wait=true', [
            'points' => array_map(fn (VectorPoint $point): array => [
                'id' => $point->chunkId,
                'vector' => $point->vector,
                'payload' => ['kind' => $point->kind->value, 'source_id' => $point->sourceId],
            ], $points),
        ]);
    }

    public function deleteSource(int $tenantId, SourceKind $kind, int $sourceId): void
    {
        $this->send('POST', '/collections/'.self::collection($tenantId).'/points/delete?wait=true', [
            'filter' => ['must' => [
                ['key' => 'kind', 'match' => ['value' => $kind->value]],
                ['key' => 'source_id', 'match' => ['value' => $sourceId]],
            ]],
        ], allow: [404]);
    }

    public function search(int $tenantId, array $vector, int $limit): array
    {
        $response = $this->send('POST', '/collections/'.self::collection($tenantId).'/points/query', ['query' => $vector, 'limit' => $limit, 'with_payload' => false, 'with_vector' => false], allow: [404]);

        if ($response->status() === 404) {
            return [];
        }

        $points = $response->json('result.points');
        $hits = [];

        foreach (is_array($points) ? $points : [] as $point) {
            if (is_array($point) && is_int($point['id'] ?? null) && (is_int($point['score'] ?? null) || is_float($point['score'] ?? null))) {
                $hits[] = new SearchHit($point['id'], (float) $point['score']);
            }
        }

        return $hits;
    }

    public function chunkIds(int $tenantId): array
    {
        $ids = [];
        $offset = null;

        do {
            $body = ['limit' => self::SCROLL_PAGE, 'with_payload' => false, 'with_vector' => false];

            if ($offset !== null) {
                $body['offset'] = $offset;
            }

            $response = $this->send('POST', '/collections/'.self::collection($tenantId).'/points/scroll', $body, allow: [404]);

            if ($response->status() === 404) {
                return [];
            }

            foreach (is_array($response->json('result.points')) ? $response->json('result.points') : [] as $point) {
                if (is_array($point) && is_int($point['id'] ?? null)) {
                    $ids[] = $point['id'];
                }
            }

            $next = $response->json('result.next_page_offset');
            $offset = is_int($next) ? $next : null;
        } while ($offset !== null);

        return $ids;
    }

    public function deleteChunks(int $tenantId, array $chunkIds): void
    {
        foreach (array_chunk($chunkIds, self::SCROLL_PAGE) as $group) {
            $this->send('POST', '/collections/'.self::collection($tenantId).'/points/delete?wait=true', ['points' => $group], allow: [404]);
        }
    }

    public function dropTenant(int $tenantId): void
    {
        $this->send('DELETE', '/collections/'.self::collection($tenantId), allow: [404]);
    }

    /** El nombre de la colección de una empresa: solo letras, números y un identificador entero. */
    public static function collection(int $tenantId): string
    {
        return 'kb_t'.$tenantId;
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  list<int>  $allow  Códigos que no son un fallo (por ejemplo 404 al borrar algo que ya no está).
     *
     * @throws IndexUnavailable
     */
    private function send(string $method, string $path, ?array $body = null, array $allow = []): Response
    {
        try {
            $response = $this->client()->send($method, $path, $body === null ? [] : ['json' => $body]);
        } catch (ConnectionException) {
            throw new IndexUnavailable('La base vectorial no responde.');
        } catch (Throwable) {
            throw new IndexUnavailable('La base vectorial falló.');
        }

        if (! $response->successful() && ! in_array($response->status(), $allow, true)) {
            throw new IndexUnavailable('La base vectorial dio una respuesta inválida.');
        }

        return $response;
    }

    private function client(): PendingRequest
    {
        return $this->http->baseUrl($this->url)->withHeaders(['api-key' => $this->apiKey])->acceptJson()->timeout($this->timeoutSeconds)->connectTimeout(3)->withoutRedirecting();
    }
}
