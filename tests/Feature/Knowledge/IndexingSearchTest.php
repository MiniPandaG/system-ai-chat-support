<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Enums\Role;
use App\Events\KnowledgeChanged;
use App\Jobs\IndexKbSource;
use App\Jobs\PurgeKbSource;
use App\Knowledge\DocumentStatus;
use App\Knowledge\Indexer;
use App\Knowledge\IndexStatus;
use App\Knowledge\Search;
use App\Knowledge\SourceKind;
use App\Knowledge\Vector\Embedder;
use App\Knowledge\Vector\EmbeddingUnavailable;
use App\Knowledge\Vector\EmbedKind;
use App\Knowledge\Vector\FakeEmbedder;
use App\Knowledge\Vector\FakeIndex;
use App\Knowledge\Vector\IndexUnavailable;
use App\Knowledge\Vector\QdrantIndex;
use App\Knowledge\Vector\TeiEmbedder;
use App\Knowledge\Vector\VectorIndex;
use App\Models\KbArticle;
use App\Models\KbChunk;
use App\Models\KbDocument;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

function fakeIndex(): FakeIndex
{
    $index = app(VectorIndex::class);
    expect($index)->toBeInstanceOf(FakeIndex::class);

    return $index;
}

/** @return array<string, mixed> */
function searchJson(object $test, User $user, string $query): array
{
    return $test->actingAs($user)->getJson('/knowledge/search?q='.urlencode($query))->assertOk()->json();
}

/** Publica un artículo por la interfaz; con la cola sincrónica ya queda indexado. */
function publishedArticle(object $test, Tenant $tenant, string $title, string $body, string $category = 'Envíos'): KbArticle
{
    $article = makeArticle($tenant, ['title' => $title, 'body' => $body, 'category' => $category]);
    $test->actingAs(kbAdmin($tenant))->post("/knowledge/articles/{$article->id}/publish")->assertSessionHasNoErrors();

    return $article;
}

describe('indexar lo publicado', function (): void {
    it('publicar un artículo lo deja «indexando» y encola el trabajo al confirmar', function (): void {
        Queue::fake();
        $tenant = ticketTenant();
        $article = makeArticle($tenant);

        $this->actingAs(kbAdmin($tenant))->post("/knowledge/articles/{$article->id}/publish");

        Queue::assertPushed(IndexKbSource::class, fn (IndexKbSource $job): bool => $job->kind === 'article' && $job->sourceId === $article->id);
        expect(articleFresh($tenant, $article->id)?->index_status)->toBe(IndexStatus::Pending)->and(articleFresh($tenant, $article->id)?->indexed_at)->toBeNull();
    });

    it('el trabajo pone los fragmentos en el índice de la empresa, marca «indexado» y avisa a la pantalla', function (): void {
        Event::fake([KnowledgeChanged::class]);
        $tenant = ticketTenant();

        $article = publishedArticle($this, $tenant, 'Cambio de dirección', 'Entra a Pedidos y cambia la dirección de envío.');

        $fresh = articleFresh($tenant, $article->id);
        expect($fresh?->index_status)->toBe(IndexStatus::Indexed)->and($fresh?->indexed_at)->not->toBeNull()
            ->and(fakeIndex()->exists($tenant->id))->toBeTrue()->and(fakeIndex()->count($tenant->id))->toBe(1);
        Event::assertDispatched(KnowledgeChanged::class, fn (KnowledgeChanged $event): bool => $event->tenantId === $tenant->id && $event->kind === SourceKind::Article && $event->id === $article->id);
    });

    it('un borrador no se indexa nunca', function (): void {
        $tenant = ticketTenant();
        $draft = makeArticle($tenant);

        app(TenantContext::class)->run($tenant, fn () => app(Indexer::class)->index(SourceKind::Article, $draft->id));

        expect(fakeIndex()->count($tenant->id))->toBe(0);
    });

    it('editar lo publicado vuelve a indexar y lo viejo deja de encontrarse', function (): void {
        $tenant = ticketTenant();
        $admin = kbAdmin($tenant);
        $article = publishedArticle($this, $tenant, 'Plazos', 'Los envíos tardan tres días hábiles.');
        expect(searchJson($this, $admin, 'envíos tardan tres días')['results'])->not->toBe([]);

        $this->actingAs($admin)->put("/knowledge/articles/{$article->id}", articlePayload(['title' => 'Plazos', 'body' => 'Garantía de fábrica por dos años completos.']))->assertRedirect();

        $titles = fn (string $query): array => array_column(searchJson($this, $admin, $query)['results'], 'text');
        expect($titles('garantía de fábrica dos años'))->toContain('Garantía de fábrica por dos años completos.')
            ->and(fakeIndex()->count($tenant->id))->toBe(1)
            ->and(implode(' ', $titles('envíos tardan tres días')))->not->toContain('tres días hábiles');
    });

    it('volver a borrador quita lo suyo del índice y limpia su estado', function (): void {
        $tenant = ticketTenant();
        $article = publishedArticle($this, $tenant, 'Plazos', 'Los envíos tardan tres días.');
        expect(fakeIndex()->count($tenant->id))->toBe(1);

        $this->actingAs(kbAdmin($tenant))->post("/knowledge/articles/{$article->id}/unpublish")->assertSessionHasNoErrors();

        expect(fakeIndex()->count($tenant->id))->toBe(0)->and(articleFresh($tenant, $article->id)?->index_status)->toBeNull();
    });

    it('eliminar quita lo suyo del índice', function (): void {
        $tenant = ticketTenant();
        $article = publishedArticle($this, $tenant, 'Plazos', 'Los envíos tardan tres días.');

        $this->actingAs(kbAdmin($tenant))->delete("/knowledge/articles/{$article->id}")->assertRedirect();

        expect(fakeIndex()->count($tenant->id))->toBe(0);
    });

    it('un documento publicado se indexa igual, con su título', function (): void {
        $tenant = ticketTenant();
        $document = makeDocument($tenant);

        $this->actingAs(kbAdmin($tenant))->post("/knowledge/documents/{$document->id}/publish")->assertSessionHasNoErrors();

        $fresh = documentFresh($tenant, $document->id);
        expect($fresh?->index_status)->toBe(IndexStatus::Indexed)->and(fakeIndex()->count($tenant->id))->toBe(1);
        $results = searchJson($this, kbAdmin($tenant), 'envíos tardan días')['results'];
        expect($results[0]['kind'])->toBe('document')->and($results[0]['title'])->toBe('Manual de envíos')->and($results[0]['url'])->toBe("/knowledge/documents/{$document->id}");
    });

    it('volver a revisión un documento y borrarlo lo quita del índice', function (): void {
        $tenant = ticketTenant();
        $admin = kbAdmin($tenant);
        $one = makeDocument($tenant);
        $two = makeDocument($tenant, ['title' => 'Otro']);
        $this->actingAs($admin)->post("/knowledge/documents/{$one->id}/publish");
        $this->actingAs($admin)->post("/knowledge/documents/{$two->id}/publish");
        expect(fakeIndex()->count($tenant->id))->toBe(2);

        $this->actingAs($admin)->post("/knowledge/documents/{$one->id}/unpublish");
        $this->actingAs($admin)->delete("/knowledge/documents/{$two->id}");

        expect(fakeIndex()->count($tenant->id))->toBe(0);
    });

    it('si la indexación falla, el trabajo se reintenta y al agotarse la fuente queda con «error de indexación»', function (): void {
        Queue::fake();
        $tenant = ticketTenant();
        $article = makeArticle($tenant, ['body' => 'Texto para citar.']);
        $this->actingAs(kbAdmin($tenant))->post("/knowledge/articles/{$article->id}/publish");
        fakeIndex()->down = true;

        expect(fn () => app(TenantContext::class)->run($tenant, fn () => (new IndexKbSource('article', $article->id))->handle(app(Indexer::class))))->toThrow(IndexUnavailable::class);
        expect(articleFresh($tenant, $article->id)?->index_status)->toBe(IndexStatus::Pending);

        app(TenantContext::class)->run($tenant, fn () => (new IndexKbSource('article', $article->id))->failed(new IndexUnavailable('caído')));

        expect(articleFresh($tenant, $article->id)?->index_status)->toBe(IndexStatus::Failed)->and(articleFresh($tenant, $article->id)?->indexed_at)->toBeNull();
    });

    it('si el modelo de embeddings falla la fuente también queda con error', function (): void {
        $tenant = ticketTenant();
        $article = makeArticle($tenant, ['body' => 'Texto.']);
        $this->actingAs(kbAdmin($tenant))->post("/knowledge/articles/{$article->id}/publish");
        app()->instance(Embedder::class, new class implements Embedder
        {
            public function embed(array $texts, EmbedKind $kind): array
            {
                throw new EmbeddingUnavailable('caído');
            }

            public function dimensions(): int
            {
                return 64;
            }
        });

        expect(fn () => app(TenantContext::class)->run($tenant, fn () => (new IndexKbSource('article', $article->id))->handle(app(Indexer::class))))->toThrow(EmbeddingUnavailable::class);
    });

    it('el trabajo es idempotente: repetirlo no duplica nada', function (): void {
        $tenant = ticketTenant();
        $article = publishedArticle($this, $tenant, 'Plazos', 'Los envíos tardan tres días.');

        foreach (range(1, 3) as $_) {
            app(TenantContext::class)->run($tenant, fn () => (new IndexKbSource('article', $article->id))->handle(app(Indexer::class)));
        }

        expect(fakeIndex()->count($tenant->id))->toBe(1)->and(articleFresh($tenant, $article->id)?->index_status)->toBe(IndexStatus::Indexed);
    });

    it('purgar es idempotente y una fuente que ya no existe se trata como vacía', function (): void {
        $tenant = ticketTenant();
        app(TenantContext::class)->run($tenant, function () use ($tenant): void {
            (new PurgeKbSource('article', 999999))->handle(app(Indexer::class));
            (new IndexKbSource('document', 999999))->handle(app(Indexer::class));
            expect(fakeIndex()->count($tenant->id))->toBe(0);
        });
    });

    it('crear el índice de la empresa usa la medida del modelo', function (): void {
        $tenant = ticketTenant();
        publishedArticle($this, $tenant, 'Plazos', 'Los envíos tardan tres días.');

        expect(app(Embedder::class)->dimensions())->toBe(FakeEmbedder::DIMENSIONS)->and(fakeIndex()->exists($tenant->id))->toBeTrue();
    });
});

describe('la búsqueda de prueba', function (): void {
    it('encuentra lo publicado más parecido primero, con su fuente, su categoría y su coincidencia', function (): void {
        $tenant = ticketTenant();
        $admin = kbAdmin($tenant);
        $shipping = publishedArticle($this, $tenant, 'Plazos de envío', 'Los envíos tardan tres días hábiles en llegar.', 'Envíos');
        publishedArticle($this, $tenant, 'Garantía', 'La garantía de fábrica cubre dos años completos.', 'Soporte');

        $results = searchJson($this, $admin, 'cuántos días tardan los envíos en llegar')['results'];

        expect($results)->toHaveCount(2)
            ->and($results[0]['title'])->toBe('Plazos de envío')->and($results[0]['category'])->toBe('Envíos')->and($results[0]['kind'])->toBe('article')
            ->and($results[0]['url'])->toBe("/knowledge/articles/{$shipping->id}")->and($results[0]['text'])->toBe('Los envíos tardan tres días hábiles en llegar.')
            ->and($results[0]['score'])->toBeFloat()->toBeGreaterThan($results[1]['score'])->toBeLessThanOrEqual(1.0)->toBeGreaterThanOrEqual(0.0);
    });

    it('un agente también busca', function (): void {
        $tenant = ticketTenant();
        publishedArticle($this, $tenant, 'Plazos', 'Los envíos tardan tres días.');

        $results = searchJson($this, ticketStaff($tenant, Role::Agent), 'envíos tardan')['results'];

        expect($results)->toHaveCount(1);
    });

    it('un borrador no aparece', function (): void {
        $tenant = ticketTenant();
        makeArticle($tenant, ['title' => 'Secreto', 'body' => 'Contenido todavía sin publicar sobre reembolsos.']);

        expect(searchJson($this, kbAdmin($tenant), 'reembolsos sin publicar')['results'])->toBe([]);
    });

    it('devuelve como mucho 5 resultados', function (): void {
        $tenant = ticketTenant();
        foreach (range(1, 8) as $i) {
            publishedArticle($this, $tenant, "Artículo {$i}", "Envíos tardan días en llegar número {$i}.");
        }

        expect(searchJson($this, kbAdmin($tenant), 'envíos tardan días')['results'])->toHaveCount(5);
    });

    it('limpia la pregunta de invisibles y de espacios antes de buscar', function (): void {
        $tenant = ticketTenant();
        publishedArticle($this, $tenant, 'Plazos', 'Los envíos tardan tres días.');

        expect(searchJson($this, kbAdmin($tenant), "  envíos\u{200B}   tardan\u{202E} ")['results'])->toHaveCount(1);
    });

    it('valida la pregunta con un mensaje específico', function (string $url, string $message): void {
        $tenant = ticketTenant();

        $this->actingAs(kbAdmin($tenant))->getJson($url)->assertStatus(422)->assertJsonPath('message', $message);
    })->with([
        'sin pregunta' => ['/knowledge/search', 'Escribe una pregunta para buscar.'],
        'vacía' => ['/knowledge/search?q=', 'Escribe una pregunta para buscar.'],
        'solo espacios' => ['/knowledge/search?q=%20%20', 'Escribe una pregunta para buscar.'],
        'en lista' => ['/knowledge/search?q[]=a', 'Escribe una pregunta para buscar.'],
    ]);

    it('rechaza una pregunta demasiado larga', function (): void {
        $tenant = ticketTenant();

        $this->actingAs(kbAdmin($tenant))->getJson('/knowledge/search?q='.str_repeat('a', 301))->assertStatus(422)->assertJsonPath('message', 'La pregunta es demasiado larga. El máximo es de 300 caracteres.');
    });

    it('si el modelo o la base vectorial no responden dice que no está disponible, sin detalles', function (): void {
        $tenant = ticketTenant();
        publishedArticle($this, $tenant, 'Plazos', 'Los envíos tardan tres días.');
        fakeIndex()->down = true;

        $response = $this->actingAs(kbAdmin($tenant))->getJson('/knowledge/search?q=envíos')->assertStatus(503);

        expect($response->json('message'))->toBe('La búsqueda no está disponible ahora. Inténtalo de nuevo en unos minutos.')
            ->and($response->getContent())->not->toContain('Vector')->not->toContain('qdrant');
    });

    it('limita las búsquedas a 30 por minuto por persona', function (): void {
        $tenant = ticketTenant();
        $admin = kbAdmin($tenant);
        RateLimiter::clear('knowledge-search:'.$admin->id);

        foreach (range(1, 30) as $_) {
            $this->actingAs($admin)->getJson('/knowledge/search?q=envíos')->assertOk();
        }

        $this->actingAs($admin)->getJson('/knowledge/search?q=envíos')->assertStatus(429);
    });

    it('el observador y quien no tiene sesión no buscan', function (): void {
        $tenant = ticketTenant();

        $this->actingAs(ticketStaff($tenant, Role::Observer))->getJson('/knowledge/search?q=envíos')->assertForbidden();
        auth()->logout();
        $this->getJson('/knowledge/search?q=envíos')->assertUnauthorized();
    });
});

describe('aislamiento entre empresas en la búsqueda', function (): void {
    it('cada empresa solo encuentra lo suyo aunque el texto sea idéntico', function (): void {
        $mine = ticketTenant();
        $theirs = ticketTenant();
        $own = publishedArticle($this, $mine, 'Mi política', 'Los envíos tardan tres días hábiles.');
        $foreign = publishedArticle($this, $theirs, 'Política ajena', 'Los envíos tardan tres días hábiles.');

        $mineResults = searchJson($this, kbAdmin($mine), 'envíos tardan tres días')['results'];
        $theirResults = searchJson($this, kbAdmin($theirs), 'envíos tardan tres días')['results'];

        expect(array_column($mineResults, 'url'))->toBe(["/knowledge/articles/{$own->id}"])
            ->and(array_column($theirResults, 'url'))->toBe(["/knowledge/articles/{$foreign->id}"])
            ->and(fakeIndex()->count($mine->id))->toBe(1)->and(fakeIndex()->count($theirs->id))->toBe(1);
    });

    it('un vector de un fragmento de otra empresa metido en mi índice nunca se muestra', function (): void {
        $mine = ticketTenant();
        $theirs = ticketTenant();
        $foreign = publishedArticle($this, $theirs, 'Política ajena', 'Los envíos tardan tres días hábiles.');
        $foreignChunk = app(TenantContext::class)->run($theirs, fn () => KbChunk::query()->where('article_id', $foreign->id)->firstOrFail());
        fakeIndex()->plant($mine->id, $foreignChunk->id, SourceKind::Article, $foreign->id, 'Los envíos tardan tres días hábiles.');

        $results = searchJson($this, kbAdmin($mine), 'envíos tardan tres días')['results'];

        expect($results)->toBe([]);
    });

    it('un vector huérfano de algo ya borrado no se muestra', function (): void {
        $tenant = ticketTenant();
        fakeIndex()->ensure($tenant->id, 64);
        fakeIndex()->plant($tenant->id, 987654, SourceKind::Article, 1, 'Los envíos tardan tres días hábiles.');

        expect(searchJson($this, kbAdmin($tenant), 'envíos tardan tres días')['results'])->toBe([]);
    });

    it('un fragmento cuya fuente ya no está publicada se descarta aunque siga en el índice', function (): void {
        $tenant = ticketTenant();
        $article = publishedArticle($this, $tenant, 'Plazos', 'Los envíos tardan tres días hábiles.');
        app(TenantContext::class)->run($tenant, fn () => KbArticle::query()->whereKey($article->id)->update(['status' => 'draft']));

        expect(searchJson($this, kbAdmin($tenant), 'envíos tardan tres días')['results'])->toBe([]);
    });

    it('un documento ya no publicado también se descarta', function (): void {
        $tenant = ticketTenant();
        $document = makeDocument($tenant);
        $this->actingAs(kbAdmin($tenant))->post("/knowledge/documents/{$document->id}/publish");
        app(TenantContext::class)->run($tenant, fn () => KbDocument::query()->whereKey($document->id)->update(['status' => DocumentStatus::Review->value]));

        expect(searchJson($this, kbAdmin($tenant), 'envíos tardan días')['results'])->toBe([]);
    });

    it('indexar el identificador de una fuente de otra empresa no hace nada en mi índice', function (): void {
        $mine = ticketTenant();
        $theirs = ticketTenant();
        $foreign = publishedArticle($this, $theirs, 'Política ajena', 'Los envíos tardan tres días hábiles.');
        $before = fakeIndex()->count($theirs->id);

        app(TenantContext::class)->run($mine, fn () => app(Indexer::class)->index(SourceKind::Article, $foreign->id));

        expect(fakeIndex()->count($mine->id))->toBe(0)->and(fakeIndex()->count($theirs->id))->toBe($before);
    });

    it('purgar desde una empresa no toca el índice de otra', function (): void {
        $mine = ticketTenant();
        $theirs = ticketTenant();
        $foreign = publishedArticle($this, $theirs, 'Política ajena', 'Los envíos tardan tres días hábiles.');

        app(TenantContext::class)->run($mine, fn () => app(Indexer::class)->purge(SourceKind::Article, $foreign->id));

        expect(fakeIndex()->count($theirs->id))->toBe(1);
    });

    it('sin empresa en contexto la búsqueda no existe', function (): void {
        expect(fn () => app(Search::class)->search('envíos'))->toThrow(NotFoundHttpException::class);
    });
});

describe('quién ve el estado de indexación', function (): void {
    it('la lista de artículos y la pantalla traen el estado de indexación', function (): void {
        $tenant = ticketTenant();
        $article = publishedArticle($this, $tenant, 'Plazos', 'Los envíos tardan tres días.');

        $list = $this->actingAs(kbAdmin($tenant))->get('/knowledge')->viewData('page')['props'];
        $page = $this->actingAs(kbAdmin($tenant))->get("/knowledge/articles/{$article->id}")->viewData('page')['props'];

        expect($list['articles'][0]['index_status'])->toBe('indexed')->and($list['tenant_id'])->toBe($tenant->id)
            ->and($page['article']['index_status'])->toBe('indexed')->and($page['tenant_id'])->toBe($tenant->id);
    });

    it('un borrador no trae estado de indexación', function (): void {
        $tenant = ticketTenant();
        makeArticle($tenant);

        $list = $this->actingAs(kbAdmin($tenant))->get('/knowledge')->viewData('page')['props'];

        expect($list['articles'][0]['index_status'])->toBeNull();
    });
});

describe('los servicios de verdad', function (): void {
    it('con «fake» en producción la aplicación se niega a arrancar', function (string $key, string $contract): void {
        $this->app['env'] = 'production';
        config([$key => 'fake']);
        $this->app->forgetInstance($contract);

        expect(fn () => app($contract))->toThrow(LogicException::class);
    })->with([
        'el índice' => ['knowledge.index.driver', VectorIndex::class],
        'los embeddings' => ['knowledge.embeddings.driver', Embedder::class],
    ]);

    it('un valor desconocido no se interpreta como «sin servicio»', function (string $key, string $contract): void {
        config([$key => 'nada']);
        $this->app->forgetInstance($contract);

        expect(fn () => app($contract))->toThrow(LogicException::class);
    })->with([
        'el índice' => ['knowledge.index.driver', VectorIndex::class],
        'los embeddings' => ['knowledge.embeddings.driver', Embedder::class],
    ]);

    it('con la configuración real se construyen los clientes de Qdrant y del modelo', function (): void {
        config(['knowledge.index.driver' => 'qdrant', 'knowledge.embeddings.driver' => 'tei']);
        $this->app->forgetInstance(VectorIndex::class);

        expect(app(VectorIndex::class))->toBeInstanceOf(QdrantIndex::class)->and(app(Embedder::class))->toBeInstanceOf(TeiEmbedder::class)
            ->and(app(Embedder::class)->dimensions())->toBe(384);
    });
});
