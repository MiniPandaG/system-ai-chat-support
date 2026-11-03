<?php

declare(strict_types=1);

use App\Knowledge\SourceKind;
use App\Knowledge\Vector\EmbeddingUnavailable;
use App\Knowledge\Vector\EmbedKind;
use App\Knowledge\Vector\IndexUnavailable;
use App\Knowledge\Vector\QdrantIndex;
use App\Knowledge\Vector\TeiEmbedder;
use App\Knowledge\Vector\VectorPoint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function qdrant(): QdrantIndex
{
    return new QdrantIndex(app(Factory::class), 'http://qdrant.test:6333', 'llave-de-prueba', 5);
}

function tei(int $dimensions = 3, int $batch = 2): TeiEmbedder
{
    return new TeiEmbedder(app(Factory::class), 'http://embeddings.test', $dimensions, $batch, 5, 'query: ', 'passage: ');
}

/** @return list<string> Las peticiones enviadas, como «MÉTODO ruta». */
function sentRequests(): array
{
    $lines = [];
    Http::assertSent(function (Request $request) use (&$lines): bool {
        $lines[] = $request->method().' '.parse_url($request->url(), PHP_URL_PATH).(parse_url($request->url(), PHP_URL_QUERY) === null ? '' : '?'.parse_url($request->url(), PHP_URL_QUERY));

        return true;
    });

    return $lines;
}

describe('QdrantIndex', function (): void {
    it('crea la colección de la empresa con la medida del modelo y los índices de la fuente, si no existe', function (): void {
        Http::fake(['*/exists' => Http::response(['result' => ['exists' => false]]), '*' => Http::response(['result' => true])]);

        qdrant()->ensure(7, 384);

        expect(sentRequests())->toBe(['GET /collections/kb_t7/exists', 'PUT /collections/kb_t7', 'PUT /collections/kb_t7/index', 'PUT /collections/kb_t7/index']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && str_ends_with($request->url(), '/collections/kb_t7') && $request['vectors'] === ['size' => 384, 'distance' => 'Cosine']);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/index') && $request['field_name'] === 'source_id' && $request['field_schema'] === 'integer');
    });

    it('si la colección ya existe no vuelve a crearla', function (): void {
        Http::fake(['*/exists' => Http::response(['result' => ['exists' => true]])]);

        qdrant()->ensure(7, 384);

        expect(sentRequests())->toBe(['GET /collections/kb_t7/exists']);
    });

    it('si otra petición la creó a la vez (409) no es un fallo', function (): void {
        Http::fake(['*/exists' => Http::response(['result' => ['exists' => false]]), '*/collections/kb_t7' => Http::response([], 409)]);

        qdrant()->ensure(7, 384);

        expect(sentRequests())->toHaveCount(2);
    });

    it('cada empresa tiene su propia colección y la llave viaja en la cabecera', function (): void {
        Http::fake(['*' => Http::response(['result' => ['points' => []]])]);

        qdrant()->search(3, [0.1, 0.2], 5);
        qdrant()->search(9, [0.1, 0.2], 5);

        expect(sentRequests())->toBe(['POST /collections/kb_t3/points/query', 'POST /collections/kb_t9/points/query']);
        Http::assertSent(fn (Request $request): bool => $request->header('api-key') === ['llave-de-prueba']);
    });

    it('guarda los fragmentos con el tipo y el identificador de la fuente, esperando a que queden', function (): void {
        Http::fake(['*' => Http::response(['result' => true])]);

        qdrant()->upsert(7, [new VectorPoint(11, [0.5, 0.5], SourceKind::Article, 3), new VectorPoint(12, [0.1, 0.9], SourceKind::Document, 4)]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && str_ends_with($request->url(), '/collections/kb_t7/points?wait=true')
            && $request['points'] === [
                ['id' => 11, 'vector' => [0.5, 0.5], 'payload' => ['kind' => 'article', 'source_id' => 3]],
                ['id' => 12, 'vector' => [0.1, 0.9], 'payload' => ['kind' => 'document', 'source_id' => 4]],
            ]);
    });

    it('guardar nada no llama al servicio', function (): void {
        Http::fake();

        qdrant()->upsert(7, []);

        Http::assertNothingSent();
    });

    it('quita una fuente por filtro de tipo e identificador, y si la colección no existe no es un fallo', function (): void {
        Http::fake(['*' => Http::response([], 404)]);

        qdrant()->deleteSource(7, SourceKind::Document, 4);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/collections/kb_t7/points/delete?wait=true')
            && $request['filter'] === ['must' => [['key' => 'kind', 'match' => ['value' => 'document']], ['key' => 'source_id', 'match' => ['value' => 4]]]]);
    });

    it('la búsqueda devuelve los identificadores y la puntuación, y sin colección no devuelve nada', function (): void {
        Http::fake(['*/kb_t7/*' => Http::response(['result' => ['points' => [['id' => 5, 'score' => 0.91], ['id' => 6, 'score' => 0.5], ['raro' => true]]]]), '*' => Http::response([], 404)]);

        $hits = qdrant()->search(7, [0.1], 3);

        expect(array_map(fn ($hit): array => [$hit->chunkId, $hit->score], $hits))->toBe([[5, 0.91], [6, 0.5]])
            ->and(qdrant()->search(8, [0.1], 3))->toBe([]);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'kb_t7') && $request['limit'] === 3 && $request['with_payload'] === false);
    });

    it('lista todos los identificadores recorriendo las páginas', function (): void {
        Http::fakeSequence()
            ->push(['result' => ['points' => [['id' => 1], ['id' => 2]], 'next_page_offset' => 3]])
            ->push(['result' => ['points' => [['id' => 3]], 'next_page_offset' => null]]);

        expect(qdrant()->chunkIds(7))->toBe([1, 2, 3]);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => ($request['offset'] ?? null) === 3);
    });

    it('quita fragmentos por identificador y borra la colección de la empresa', function (): void {
        Http::fake(['*' => Http::response(['result' => true])]);

        qdrant()->deleteChunks(7, [4, 5]);
        qdrant()->dropTenant(7);

        expect(sentRequests())->toBe(['POST /collections/kb_t7/points/delete?wait=true', 'DELETE /collections/kb_t7']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'points/delete') && $request['points'] === [4, 5]);
    });

    it('un fallo del servicio se dice con un mensaje genérico, sin direcciones ni respuestas', function (): void {
        Http::fake(['*' => Http::response('Traza interna: /var/lib/qdrant/secreto', 500)]);

        try {
            qdrant()->search(7, [0.1], 3);
            $this->fail('Debió fallar.');
        } catch (IndexUnavailable $error) {
            expect($error->getMessage())->toBe('La base vectorial dio una respuesta inválida.')->not->toContain('qdrant.test')->not->toContain('secreto');
        }
    });

    it('si no hay conexión también es un fallo genérico', function (): void {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to qdrant.test port 6333'));

        try {
            qdrant()->upsert(7, [new VectorPoint(1, [0.1], SourceKind::Article, 1)]);
            $this->fail('Debió fallar.');
        } catch (IndexUnavailable $error) {
            expect($error->getMessage())->toBe('La base vectorial no responde.')->not->toContain('6333');
        }
    });

    it('no sigue redirecciones', function (): void {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data'])]);

        expect(fn () => qdrant()->search(7, [0.1], 3))->toThrow(IndexUnavailable::class);
        Http::assertSentCount(1);
    });

    it('el nombre de la colección solo lleva un entero', function (): void {
        expect(QdrantIndex::collection(12))->toBe('kb_t12');
    });
});

describe('TeiEmbedder', function (): void {
    it('pide los vectores con el prefijo del tipo, normalizados y acortando lo largo', function (): void {
        Http::fake(['*' => Http::response([[0.1, 0.2, 0.3]])]);

        $vectors = tei()->embed(['¿Cuánto tarda el envío?'], EmbedKind::Query);

        expect($vectors)->toBe([[0.1, 0.2, 0.3]]);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://embeddings.test/embed' && $request['inputs'] === ['query: ¿Cuánto tarda el envío?'] && $request['normalize'] === true && $request['truncate'] === true);
    });

    it('los pasajes llevan su propio prefijo y se piden por lotes, en orden', function (): void {
        Http::fake(['*' => Http::sequence()->push([[1.0, 0.0, 0.0], [0.0, 1.0, 0.0]])->push([[0.0, 0.0, 1.0]])]);

        $vectors = tei()->embed(['uno', 'dos', 'tres'], EmbedKind::Passage);

        expect($vectors)->toBe([[1.0, 0.0, 0.0], [0.0, 1.0, 0.0], [0.0, 0.0, 1.0]]);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request['inputs'] === ['passage: uno', 'passage: dos']);
        Http::assertSent(fn (Request $request): bool => $request['inputs'] === ['passage: tres']);
    });

    it('rechaza una respuesta con otra medida, otra cantidad o algo que no son números', function (mixed $body): void {
        Http::fake(['*' => Http::response($body)]);

        expect(fn () => tei()->embed(['uno'], EmbedKind::Query))->toThrow(EmbeddingUnavailable::class, 'El servicio de embeddings dio una respuesta inválida.');
    })->with([
        'medida distinta' => [[[0.1, 0.2]]],
        'cantidad distinta' => [[[0.1, 0.2, 0.3], [0.1, 0.2, 0.3]]],
        'no son números' => [[['a', 'b', 'c']]],
        'no es una lista' => [['error' => 'modelo cargando']],
    ]);

    it('un fallo del servicio se dice con un mensaje genérico', function (): void {
        Http::fake(['*' => Http::response('Traza interna en /models/e5', 503)]);

        try {
            tei()->embed(['uno'], EmbedKind::Query);
            $this->fail('Debió fallar.');
        } catch (EmbeddingUnavailable $error) {
            expect($error->getMessage())->not->toContain('models')->not->toContain('embeddings.test');
        }
    });

    it('si no hay conexión es un fallo genérico', function (): void {
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host: embeddings.test'));

        expect(fn () => tei()->embed(['uno'], EmbedKind::Query))->toThrow(EmbeddingUnavailable::class, 'El servicio de embeddings no responde.');
    });

    it('sin textos no llama al servicio y declara su medida', function (): void {
        Http::fake();

        expect(tei()->embed([], EmbedKind::Passage))->toBe([])->and(tei(384)->dimensions())->toBe(384);
        Http::assertNothingSent();
    });
});
