<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Enums\Role;
use App\Jobs\IndexKbSource;
use App\Knowledge\DocumentStatus;
use App\Knowledge\IndexStatus;
use App\Knowledge\KnowledgePurge;
use App\Knowledge\Reindexer;
use App\Knowledge\SourceKind;
use App\Knowledge\Vector\FakeIndex;
use App\Knowledge\Vector\IndexUnavailable;
use App\Knowledge\Vector\VectorIndex;
use App\Models\KbArticle;
use App\Models\KbChunk;
use App\Models\KbContext;
use App\Models\KbDocument;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    useTemporaryAttachmentDisk();
});

function theIndex(): FakeIndex
{
    $index = app(VectorIndex::class);
    expect($index)->toBeInstanceOf(FakeIndex::class);

    return $index;
}

function publishedDoc(object $test, Tenant $tenant, string $text = 'Los envíos tardan de 3 a 5 días.'): KbDocument
{
    $document = makeDocument($tenant, ['body' => $text]);
    $test->actingAs(kbAdmin($tenant))->post("/knowledge/documents/{$document->id}/publish");

    return $document;
}

function publishedArt(object $test, Tenant $tenant, string $body = 'Entra a Pedidos y cambia la dirección.'): KbArticle
{
    $article = makeArticle($tenant, ['body' => $body]);
    $test->actingAs(kbAdmin($tenant))->post("/knowledge/articles/{$article->id}/publish");

    return $article;
}

describe('reintentar la indexación', function (): void {
    it('un administrador vuelve a pedirla para un artículo y para un documento publicados', function (): void {
        $tenant = ticketTenant();
        $article = publishedArt($this, $tenant);
        $document = publishedDoc($this, $tenant);
        Queue::fake();

        $this->actingAs(kbAdmin($tenant))->post("/knowledge/articles/{$article->id}/reindex")->assertSessionHasNoErrors();
        $this->actingAs(kbAdmin($tenant))->post("/knowledge/documents/{$document->id}/reindex")->assertSessionHasNoErrors();

        Queue::assertPushed(IndexKbSource::class, 2);
        expect(articleFresh($tenant, $article->id)?->index_status)->toBe(IndexStatus::Pending)->and(documentFresh($tenant, $document->id)?->index_status)->toBe(IndexStatus::Pending);
    });

    it('reindexar algo que no está publicado no hace nada', function (): void {
        Queue::fake();
        $tenant = ticketTenant();
        $draft = makeArticle($tenant);
        $document = makeDocument($tenant);

        $this->actingAs(kbAdmin($tenant))->post("/knowledge/articles/{$draft->id}/reindex")->assertSessionHasNoErrors();
        $this->actingAs(kbAdmin($tenant))->post("/knowledge/documents/{$document->id}/reindex")->assertSessionHasNoErrors();

        Queue::assertNothingPushed();
        expect(articleFresh($tenant, $draft->id)?->index_status)->toBeNull();
    });

    it('un agente, un observador y otra empresa no pueden', function (): void {
        Queue::fake();
        $tenant = ticketTenant();
        $other = ticketTenant();
        $article = publishedArt($this, $tenant);
        $foreign = publishedArt($this, $other);
        Queue::fake();

        $this->actingAs(ticketStaff($tenant, Role::Agent))->post("/knowledge/articles/{$article->id}/reindex")->assertForbidden();
        $this->actingAs(ticketStaff($tenant, Role::Observer))->post("/knowledge/articles/{$article->id}/reindex")->assertForbidden();
        $this->actingAs(kbAdmin($tenant))->post("/knowledge/articles/{$foreign->id}/reindex")->assertNotFound();

        Queue::assertNothingPushed();
    });
});

describe('knowledge:reindex', function (): void {
    it('vuelve a encolar todo lo publicado de todas las empresas', function (): void {
        $one = ticketTenant();
        $two = ticketTenant();
        publishedArt($this, $one);
        publishedDoc($this, $one);
        publishedArt($this, $two);
        makeArticle($two, ['title' => 'Borrador']);
        Queue::fake();

        $this->artisan('knowledge:reindex')->expectsOutput('Fuentes encoladas para indexar: 3.')->assertSuccessful();

        Queue::assertPushed(IndexKbSource::class, 3);
    });

    it('con --tenant solo toca esa empresa', function (): void {
        $one = ticketTenant();
        $two = ticketTenant();
        publishedArt($this, $one);
        publishedArt($this, $two);
        Queue::fake();

        $this->artisan('knowledge:reindex', ['--tenant' => (string) $one->id])->expectsOutput('Fuentes encoladas para indexar: 1.')->assertSuccessful();
    });

    it('rechaza un identificador que no es un número', function (): void {
        $this->artisan('knowledge:reindex', ['--tenant' => '1; DROP TABLE'])->assertFailed();
    });
});

describe('knowledge:reconcile', function (): void {
    it('quita del índice lo que ya no existe en la base y no toca lo vigente', function (): void {
        $tenant = ticketTenant();
        $article = publishedArt($this, $tenant);
        theIndex()->plant($tenant->id, 987654, SourceKind::Article, 999, 'huérfano de algo borrado');
        expect(theIndex()->count($tenant->id))->toBe(2);

        $this->artisan('knowledge:reconcile', ['--tenant' => (string) $tenant->id])->expectsOutput('Quitados del índice: 1. Fuentes encoladas para indexar: 0.')->assertSuccessful();

        expect(theIndex()->count($tenant->id))->toBe(1)->and(chunksOf($tenant, $article))->toHaveCount(1);
    });

    it('vuelve a indexar la fuente a la que le faltan fragmentos en el índice', function (): void {
        Queue::fake();
        $tenant = ticketTenant();
        $article = makeArticle($tenant, ['body' => 'Entra a Pedidos y cambia la dirección.']);
        $this->actingAs(kbAdmin($tenant))->post("/knowledge/articles/{$article->id}/publish");
        Queue::fake();
        // El índice quedó vacío (por ejemplo, se perdió el volumen): la base manda.
        theIndex()->dropTenant($tenant->id);

        $this->artisan('knowledge:reconcile', ['--tenant' => (string) $tenant->id])->expectsOutput('Quitados del índice: 0. Fuentes encoladas para indexar: 1.')->assertSuccessful();

        Queue::assertPushed(IndexKbSource::class, fn (IndexKbSource $job): bool => $job->kind === 'article' && $job->sourceId === $article->id);
    });

    it('un vector de un fragmento de otra empresa metido en mi índice se quita del mío y el de ella queda', function (): void {
        $mine = ticketTenant();
        $theirs = ticketTenant();
        $foreign = publishedArt($this, $theirs);
        $foreignChunk = app(TenantContext::class)->run($theirs, fn () => KbChunk::query()->where('article_id', $foreign->id)->firstOrFail());
        theIndex()->ensure($mine->id, 64);
        theIndex()->plant($mine->id, $foreignChunk->id, SourceKind::Article, $foreign->id, 'Entra a Pedidos');

        $this->artisan('knowledge:reconcile', ['--tenant' => (string) $mine->id])->assertSuccessful();

        expect(theIndex()->count($mine->id))->toBe(0)->and(theIndex()->count($theirs->id))->toBe(1);
    });

    it('si la base vectorial no responde lo dice y termina con error, sin mostrar detalles', function (): void {
        $tenant = ticketTenant();
        publishedArt($this, $tenant);
        theIndex()->down = true;

        $this->artisan('knowledge:reconcile')->expectsOutputToContain('la base vectorial no responde')->assertFailed();
    });

    it('rechaza un identificador que no es un número', function (): void {
        $this->artisan('knowledge:reconcile', ['--tenant' => 'abc'])->assertFailed();
    });
});

describe('el borrado completo de una empresa', function (): void {
    it('elimina artículos, documentos, archivos, fragmentos, contexto e índice, y solo de esa empresa', function (): void {
        $mine = ticketTenant();
        $theirs = ticketTenant();
        $admin = kbAdmin($mine);
        publishedArt($this, $mine);
        publishedDoc($this, $mine);
        $pending = uploadedDocument($mine, 'sin revisar todavía');
        $this->actingAs($admin)->put('/knowledge/context', ['body' => 'Somos una tienda.']);
        $foreignArticle = publishedArt($this, $theirs);
        $this->actingAs(kbAdmin($theirs))->put('/knowledge/context', ['body' => 'Contexto ajeno.']);
        expect(theIndex()->count($mine->id))->toBe(2)->and(Storage::disk(config()->string('files.disk'))->exists((string) $pending->path))->toBeTrue();

        app(KnowledgePurge::class)->forTenant($mine);

        $count = fn (Tenant $tenant, string $model): int => app(TenantContext::class)->run($tenant, fn (): int => $model::query()->count());
        expect($count($mine, KbArticle::class))->toBe(0)->and($count($mine, KbDocument::class))->toBe(0)->and($count($mine, KbChunk::class))->toBe(0)->and($count($mine, KbContext::class))->toBe(0)
            ->and(theIndex()->exists($mine->id))->toBeFalse()
            ->and(Storage::disk(config()->string('files.disk'))->exists((string) $pending->path))->toBeFalse()
            ->and($count($theirs, KbArticle::class))->toBe(1)->and($count($theirs, KbChunk::class))->toBe(1)->and($count($theirs, KbContext::class))->toBe(1)
            ->and(theIndex()->count($theirs->id))->toBe(1)->and(articleFresh($theirs, $foreignArticle->id))->not->toBeNull();
    });

    it('se puede repetir sin fallar y sin tocar nada de otra empresa', function (): void {
        $mine = ticketTenant();
        $theirs = ticketTenant();
        publishedArt($this, $theirs);

        app(KnowledgePurge::class)->forTenant($mine);
        app(KnowledgePurge::class)->forTenant($mine);

        expect(theIndex()->count($theirs->id))->toBe(1);
    });

    it('si el índice no responde no borra nada de la base, para poder repetirlo', function (): void {
        $tenant = ticketTenant();
        $article = publishedArt($this, $tenant);
        theIndex()->down = true;

        expect(fn () => app(KnowledgePurge::class)->forTenant($tenant))->toThrow(IndexUnavailable::class);

        expect(articleFresh($tenant, $article->id))->not->toBeNull()->and(chunksOf($tenant, $article))->toHaveCount(1);
    });
});

describe('todo lo que desaparece al borrar', function (): void {
    it('eliminar un artículo publicado deja sin fragmentos, sin vectores y sin resultados de búsqueda', function (): void {
        $tenant = ticketTenant();
        $article = publishedArt($this, $tenant, 'Los envíos tardan tres días hábiles.');

        $this->actingAs(kbAdmin($tenant))->delete("/knowledge/articles/{$article->id}");

        expect(chunkTotal($tenant))->toBe(0)->and(theIndex()->count($tenant->id))->toBe(0)
            ->and($this->actingAs(kbAdmin($tenant))->getJson('/knowledge/search?q=envíos tardan')->json('results'))->toBe([]);
    });

    it('eliminar un documento publicado deja sin fragmentos, sin vectores y sin resultados de búsqueda', function (): void {
        $tenant = ticketTenant();
        $document = publishedDoc($this, $tenant);

        $this->actingAs(kbAdmin($tenant))->delete("/knowledge/documents/{$document->id}");

        expect(chunkTotal($tenant))->toBe(0)->and(theIndex()->count($tenant->id))->toBe(0)
            ->and($this->actingAs(kbAdmin($tenant))->getJson('/knowledge/search?q=envíos tardan')->json('results'))->toBe([]);
    });

    it('un documento ya publicado no cuenta como publicado después de volver a revisión', function (): void {
        $tenant = ticketTenant();
        $document = publishedDoc($this, $tenant);

        $this->actingAs(kbAdmin($tenant))->post("/knowledge/documents/{$document->id}/unpublish");

        expect(documentFresh($tenant, $document->id)?->status)->toBe(DocumentStatus::Review)->and(theIndex()->count($tenant->id))->toBe(0);
    });
});

describe('reindexar con el servicio', function (): void {
    it('el servicio solo reindexa lo publicado de la empresa en contexto', function (): void {
        Queue::fake();
        $mine = ticketTenant();
        $theirs = ticketTenant();
        publishedArt($this, $mine);
        publishedArt($this, $theirs);
        Queue::fake();

        $count = app(TenantContext::class)->run($mine, fn (): int => app(Reindexer::class)->reindexAll());

        expect($count)->toBe(1);
        Queue::assertPushed(IndexKbSource::class, 1);
    });
});
