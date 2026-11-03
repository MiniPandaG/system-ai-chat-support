<?php

declare(strict_types=1);

require_once __DIR__.'/../Tickets/Helpers.php';

use App\Attachments\Scanner;
use App\Enums\Role;
use App\Jobs\ExtractKbDocument;
use App\Jobs\ScanKbDocument;
use App\Knowledge\ArticleStatus;
use App\Knowledge\DocumentStatus;
use App\Knowledge\TextExtractor;
use App\Models\KbArticle;
use App\Models\KbChunk;
use App\Models\KbDocument;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function kbAdmin(Tenant $tenant): User
{
    return ticketStaff($tenant, Role::Admin);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function makeArticle(Tenant $tenant, array $attributes = []): KbArticle
{
    return app(TenantContext::class)->run($tenant, function () use ($attributes): KbArticle {
        $article = new KbArticle;
        $article->forceFill([
            'title' => 'Cómo cambiar la dirección de envío',
            'body' => "Entra a tu cuenta y abre **Pedidos**.\n\n- Elige el pedido\n- Cambia la dirección",
            'category' => 'Envíos',
            'status' => ArticleStatus::Draft,
            ...$attributes,
        ])->save();

        return $article;
    });
}

/** @return array<string, string> */
function articlePayload(array $overrides = []): array
{
    return ['title' => 'Política de devoluciones', 'body' => "Aceptamos devoluciones hasta 30 días después de la compra.\n\nEl reembolso tarda 5 días hábiles.", 'category' => 'Pagos', ...$overrides];
}

function articleFresh(Tenant $tenant, int $id): ?KbArticle
{
    return app(TenantContext::class)->run($tenant, fn () => KbArticle::query()->find($id));
}

function articleCount(Tenant $tenant): int
{
    return app(TenantContext::class)->run($tenant, fn (): int => KbArticle::query()->count());
}

/**
 * @param  array<string, mixed>  $attributes
 */
function makeDocument(Tenant $tenant, array $attributes = []): KbDocument
{
    return app(TenantContext::class)->run($tenant, function () use ($attributes): KbDocument {
        $document = new KbDocument;
        $document->forceFill([
            'title' => 'Manual de envíos',
            'original_name' => 'manual-de-envios.pdf',
            'type' => 'pdf',
            'size_bytes' => 1234,
            'sha256' => str_repeat('a', 64),
            'pages' => 3,
            'category' => 'Envíos',
            'body' => "Los envíos tardan de 3 a 5 días.\n\nEl costo depende de la ciudad.",
            'status' => DocumentStatus::Review,
            'disk' => config('files.disk'),
            'path' => null,
            ...$attributes,
        ])->save();

        return $document;
    });
}

function documentFresh(Tenant $tenant, int $id): ?KbDocument
{
    return app(TenantContext::class)->run($tenant, fn () => KbDocument::query()->find($id));
}

function documentCount(Tenant $tenant): int
{
    return app(TenantContext::class)->run($tenant, fn (): int => KbDocument::query()->count());
}

/** @return list<string> Los textos de los fragmentos de un artículo o documento, en orden. */
function chunksOf(Tenant $tenant, KbArticle|KbDocument $source): array
{
    $column = $source instanceof KbArticle ? 'article_id' : 'document_id';

    return app(TenantContext::class)->run($tenant, fn (): array => KbChunk::query()->where($column, $source->id)->orderBy('position')->pluck('body')->all());
}

function chunkTotal(Tenant $tenant): int
{
    return app(TenantContext::class)->run($tenant, fn (): int => KbChunk::query()->count());
}

/** Un documento recién subido, con su archivo ya en el disco, esperando al antivirus. */
function uploadedDocument(Tenant $tenant, string $content, string $type = 'txt'): KbDocument
{
    $path = 'tenants/'.$tenant->id.'/knowledge/'.strtolower((string) Str::ulid());
    Storage::disk(config()->string('files.disk'))->put($path, $content);

    return makeDocument($tenant, ['type' => $type, 'status' => DocumentStatus::Scanning, 'body' => null, 'pages' => null, 'path' => $path]);
}

function runScanJob(Tenant $tenant, KbDocument $document): void
{
    app(TenantContext::class)->run($tenant, fn () => (new ScanKbDocument($document->id))->handle(app(Scanner::class), app(AuditLogger::class)));
}

function runExtractJob(Tenant $tenant, KbDocument $document): void
{
    app(TenantContext::class)->run($tenant, fn () => (new ExtractKbDocument($document->id))->handle(app(TextExtractor::class)));
}
