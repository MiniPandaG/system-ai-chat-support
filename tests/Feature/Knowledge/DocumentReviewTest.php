<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Enums\AuditEvent;
use App\Knowledge\DocumentStatus;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    useTemporaryAttachmentDisk();
});

describe('revisar y editar un documento', function (): void {
    it('en revisión se editan el título, la categoría y el texto, limpiando el texto, sin auditar cada cambio', function (): void {
        $tenant = ticketTenant();
        $admin = kbAdmin($tenant);
        $document = makeDocument($tenant);

        $this->actingAs($admin)->put("/knowledge/documents/{$document->id}", ['title' => "  Guía \u{200B}  de envíos ", 'category' => ' Logística ', 'body' => "Texto\u{202E} corregido\n\n\n\nfin"])->assertRedirect();

        $fresh = documentFresh($tenant, $document->id);
        expect($fresh?->title)->toBe('Guía de envíos')->and($fresh?->category)->toBe('Logística')->and($fresh?->body)->toBe("Texto corregido\n\n\n\nfin")
            ->and($fresh?->updated_by)->toBe($admin->id)->and($fresh?->status)->toBe(DocumentStatus::Review)
            ->and(AuditLog::query()->where('event', 'like', 'knowledge.document%')->count())->toBe(0);
    });

    it('valida cada campo con un mensaje específico', function (string $field, mixed $value, string $message): void {
        $tenant = ticketTenant();
        $document = makeDocument($tenant);

        $this->actingAs(kbAdmin($tenant))->put("/knowledge/documents/{$document->id}", ['title' => 'Título', 'body' => 'Texto', 'category' => 'Cat', $field => $value])->assertSessionHasErrors([$field => $message]);

        expect(documentFresh($tenant, $document->id)?->title)->toBe('Manual de envíos');
    })->with([
        'sin título' => ['title', '', 'Escribe un título para el artículo.'],
        'título largo' => ['title', str_repeat('a', 161), 'El título es demasiado largo. El máximo es de 160 caracteres.'],
        'título con control' => ['title', "Hola\x07", 'El título tiene caracteres que no se pueden guardar.'],
        'texto largo' => ['body', str_repeat('a', 300001), 'El contenido es demasiado largo. El máximo es de 300.000 caracteres.'],
        'categoría larga' => ['category', str_repeat('a', 41), 'La categoría es demasiado larga. El máximo es de 40 caracteres.'],
    ]);

    it('solo se edita lo que está en revisión: lo publicado, lo que se procesa y lo fallido no', function (DocumentStatus $status): void {
        $tenant = ticketTenant();
        $document = makeDocument($tenant, ['status' => $status]);

        $this->actingAs(kbAdmin($tenant))->put("/knowledge/documents/{$document->id}", ['title' => 'Cambiado', 'body' => 'Texto nuevo'])
            ->assertSessionHasErrors(['body' => 'Solo se puede editar un documento mientras está en revisión. Vuelve a revisión para cambiarlo.']);

        expect(documentFresh($tenant, $document->id)?->title)->toBe('Manual de envíos');
    })->with([DocumentStatus::Published, DocumentStatus::Scanning, DocumentStatus::Extracting, DocumentStatus::Failed, DocumentStatus::Blocked]);

    it('ignora los campos que el cliente no debe escribir', function (): void {
        $tenant = ticketTenant();
        $other = ticketTenant();
        $document = makeDocument($tenant);

        $this->actingAs(kbAdmin($tenant))->put("/knowledge/documents/{$document->id}", ['title' => 'Nuevo', 'body' => 'Texto', 'status' => 'published', 'tenant_id' => $other->id, 'path' => '/etc/passwd', 'type' => 'exe'])->assertSessionHasNoErrors();

        $fresh = documentFresh($tenant, $document->id);
        expect($fresh?->status)->toBe(DocumentStatus::Review)->and($fresh?->tenant_id)->toBe($tenant->id)->and($fresh?->path)->toBeNull()->and($fresh?->type)->toBe('pdf');
    });
});

describe('publicar un documento', function (): void {
    it('publicar crea los fragmentos del texto, fija la fecha y audita una sola vez aunque se repita', function (): void {
        $tenant = ticketTenant();
        $document = makeDocument($tenant);

        $this->actingAs(kbAdmin($tenant))->post("/knowledge/documents/{$document->id}/publish")->assertSessionHasNoErrors();
        $this->actingAs(kbAdmin($tenant))->post("/knowledge/documents/{$document->id}/publish")->assertSessionHasNoErrors();

        $fresh = documentFresh($tenant, $document->id);
        expect($fresh?->status)->toBe(DocumentStatus::Published)->and($fresh?->published_at)->not->toBeNull()
            ->and(chunksOf($tenant, $document))->toBe(["Los envíos tardan de 3 a 5 días.\n\nEl costo depende de la ciudad."])
            ->and(AuditLog::query()->where('event', AuditEvent::KnowledgeDocumentPublished->value)->count())->toBe(1);
    });

    it('un texto largo se reparte en varios fragmentos que caben en el tamaño máximo', function (): void {
        $tenant = ticketTenant();
        $text = collect(range(1, 40))->map(fn (int $i): string => "Párrafo {$i}: ".str_repeat('contenido útil ', 12))->implode("\n\n");
        $document = makeDocument($tenant, ['body' => $text]);

        $this->actingAs(kbAdmin($tenant))->post("/knowledge/documents/{$document->id}/publish")->assertSessionHasNoErrors();

        $chunks = chunksOf($tenant, $document);
        expect(count($chunks))->toBeGreaterThan(5);
        foreach ($chunks as $chunk) {
            expect(mb_strlen($chunk))->toBeLessThanOrEqual(800);
        }
    });

    it('no se publica lo que todavía se procesa, lo fallido, lo bloqueado ni lo vacío', function (): void {
        $tenant = ticketTenant();
        $admin = kbAdmin($tenant);

        foreach ([DocumentStatus::Scanning, DocumentStatus::Extracting, DocumentStatus::Failed, DocumentStatus::Blocked] as $status) {
            $document = makeDocument($tenant, ['status' => $status]);
            $this->actingAs($admin)->post("/knowledge/documents/{$document->id}/publish")->assertSessionHasErrors(['body' => 'El documento todavía no está listo para publicarse.']);
            expect(chunksOf($tenant, $document))->toBe([]);
        }

        $empty = makeDocument($tenant, ['body' => '  ']);
        $this->actingAs($admin)->post("/knowledge/documents/{$empty->id}/publish")->assertSessionHasErrors(['body' => 'Escribe el contenido del artículo antes de publicarlo.']);
        expect(documentFresh($tenant, $empty->id)?->status)->toBe(DocumentStatus::Review);
    });

    it('si los fragmentos pasarían el tope de la empresa no se publica nada', function (): void {
        config(['knowledge.chunks.max_per_tenant' => 1]);
        $tenant = ticketTenant();
        $first = makeDocument($tenant);
        $second = makeDocument($tenant, ['title' => 'Otro']);
        $admin = kbAdmin($tenant);

        $this->actingAs($admin)->post("/knowledge/documents/{$first->id}/publish")->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/knowledge/documents/{$second->id}/publish")->assertSessionHasErrors(['body' => 'Esto superaría el máximo de 1 fragmentos de conocimiento por empresa. Elimina o despublica contenido antes.']);

        expect(documentFresh($tenant, $second->id)?->status)->toBe(DocumentStatus::Review)->and(chunkTotal($tenant))->toBe(1)
            ->and(AuditLog::query()->where('event', AuditEvent::KnowledgeDocumentPublished->value)->count())->toBe(1);
    });

    it('el tope de fragmentos es de cada empresa', function (): void {
        config(['knowledge.chunks.max_per_tenant' => 1]);
        $one = ticketTenant();
        $two = ticketTenant();
        $docOne = makeDocument($one);
        $docTwo = makeDocument($two);

        $this->actingAs(kbAdmin($one))->post("/knowledge/documents/{$docOne->id}/publish")->assertSessionHasNoErrors();
        $this->actingAs(kbAdmin($two))->post("/knowledge/documents/{$docTwo->id}/publish")->assertSessionHasNoErrors();

        expect(chunkTotal($one))->toBe(1)->and(chunkTotal($two))->toBe(1);
    });

    it('volver a revisión borra los fragmentos y se audita; publicar de nuevo los rehace con el texto editado', function (): void {
        $tenant = ticketTenant();
        $admin = kbAdmin($tenant);
        $document = makeDocument($tenant);

        $this->actingAs($admin)->post("/knowledge/documents/{$document->id}/publish");
        $this->actingAs($admin)->post("/knowledge/documents/{$document->id}/unpublish")->assertSessionHasNoErrors();

        expect(documentFresh($tenant, $document->id)?->status)->toBe(DocumentStatus::Review)->and(chunksOf($tenant, $document))->toBe([])
            ->and(AuditLog::query()->where('event', AuditEvent::KnowledgeDocumentUnpublished->value)->count())->toBe(1);

        $this->actingAs($admin)->put("/knowledge/documents/{$document->id}", ['title' => 'Manual', 'body' => 'Texto corregido.']);
        $this->actingAs($admin)->post("/knowledge/documents/{$document->id}/publish");

        expect(chunksOf($tenant, $document))->toBe(['Texto corregido.']);
    });
});

describe('borrar un documento', function (): void {
    it('elimina el documento, sus fragmentos y su archivo y audita solo el identificador', function (): void {
        $tenant = ticketTenant();
        $admin = kbAdmin($tenant);
        $document = makeDocument($tenant);
        $this->actingAs($admin)->post("/knowledge/documents/{$document->id}/publish");

        $this->actingAs($admin)->delete("/knowledge/documents/{$document->id}")->assertRedirect('/knowledge/documents');

        $log = AuditLog::query()->where('event', AuditEvent::KnowledgeDocumentDeleted->value)->firstOrFail();
        expect(documentFresh($tenant, $document->id))->toBeNull()->and(chunkTotal($tenant))->toBe(0)
            ->and($log->context)->toEqual(['document_id' => $document->id])->and(json_encode($log->context))->not->toContain('Manual');
    });

    it('un documento todavía por revisar también borra su archivo del disco', function (): void {
        $tenant = ticketTenant();
        $document = uploadedDocument($tenant, 'texto sin revisar');
        $path = (string) $document->path;
        expect(Storage::disk(config()->string('files.disk'))->exists($path))->toBeTrue();

        $this->actingAs(kbAdmin($tenant))->delete("/knowledge/documents/{$document->id}")->assertRedirect();

        expect(Storage::disk(config()->string('files.disk'))->exists($path))->toBeFalse()->and(documentFresh($tenant, $document->id))->toBeNull();
    });

    it('borrar no toca los fragmentos ni los documentos de otra empresa', function (): void {
        $mine = ticketTenant();
        $theirs = ticketTenant();
        $mineDoc = makeDocument($mine);
        $theirDoc = makeDocument($theirs);
        $this->actingAs(kbAdmin($mine))->post("/knowledge/documents/{$mineDoc->id}/publish");
        $this->actingAs(kbAdmin($theirs))->post("/knowledge/documents/{$theirDoc->id}/publish");

        $this->actingAs(kbAdmin($mine))->delete("/knowledge/documents/{$mineDoc->id}");

        expect(chunkTotal($mine))->toBe(0)->and(chunkTotal($theirs))->toBe(1)->and(documentFresh($theirs, $theirDoc->id))->not->toBeNull();
    });
});
