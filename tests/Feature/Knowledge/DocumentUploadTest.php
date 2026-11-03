<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Enums\AuditEvent;
use App\Enums\Role;
use App\Jobs\ScanKbDocument;
use App\Knowledge\DocumentFailure;
use App\Knowledge\DocumentStatus;
use App\Models\AuditLog;
use App\Models\KbDocument;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\Documents;

beforeEach(function (): void {
    useTemporaryAttachmentDisk();
    Queue::fake();
});

function uploadDocument(object $test, User $user, string $name, string $content): TestResponse
{
    return $test->actingAs($user)->post('/knowledge/documents', ['file' => Documents::upload($name, $content)]);
}

describe('subir un documento', function (): void {
    it('un PDF, un TXT y un Markdown válidos quedan guardados «por revisar» y encolados para el antivirus', function (string $name, string $content, string $type): void {
        $tenant = ticketTenant();
        $admin = kbAdmin($tenant);

        $response = uploadDocument($this, $admin, $name, $content);

        $document = app(TenantContext::class)->run($tenant, fn () => KbDocument::query()->firstOrFail());
        $response->assertRedirect("/knowledge/documents/{$document->id}");
        expect($document->status)->toBe(DocumentStatus::Scanning)
            ->and($document->type)->toBe($type)
            ->and($document->tenant_id)->toBe($tenant->id)
            ->and($document->created_by)->toBe($admin->id)
            ->and($document->body)->toBeNull()
            ->and($document->path)->toStartWith("tenants/{$tenant->id}/knowledge/")
            ->and($document->path)->not->toContain('.')
            ->and(Storage::disk($document->disk)->exists((string) $document->path))->toBeTrue();
        Queue::assertPushed(ScanKbDocument::class, fn (ScanKbDocument $job): bool => $job->documentId === $document->id);
        $log = AuditLog::query()->where('event', AuditEvent::KnowledgeDocumentUploaded->value)->firstOrFail();
        expect($log->context)->toEqual(['document_id' => $document->id]);
    })->with([
        'pdf' => ['politicas.pdf', Documents::pdf('Politicas'), 'pdf'],
        'txt' => ['politicas.txt', "Política de envíos.\n\nTarda 3 días.", 'txt'],
        'markdown' => ['politicas.md', "# Políticas\n\n- Envíos\n- Cambios", 'md'],
    ]);

    it('el título sale del nombre del archivo y el nombre se limpia de rutas y caracteres de control', function (): void {
        $tenant = ticketTenant();

        uploadDocument($this, kbAdmin($tenant), "C:\\Users\\ana\\Guía\x07 de envíos.txt", 'Texto de la guía.');

        $document = app(TenantContext::class)->run($tenant, fn () => KbDocument::query()->firstOrFail());
        expect($document->original_name)->toBe('Guía de envíos.txt')->and($document->title)->toBe('Guía de envíos');
    });

    it('rechaza lo que no cumple con un mensaje específico y no guarda nada', function (string $name, string $content, string $message): void {
        $tenant = ticketTenant();

        uploadDocument($this, kbAdmin($tenant), $name, $content)->assertSessionHasErrors(['file' => $message]);

        expect(documentCount($tenant))->toBe(0)->and(Storage::disk(config()->string('files.disk'))->allFiles())->toBe([]);
        Queue::assertNothingPushed();
    })->with([
        'un ejecutable que dice ser PDF' => ['guia.pdf', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF", 'La extensión del archivo no corresponde a lo que contiene. Guárdalo de nuevo con su formato real.'],
        'un texto que dice ser PDF' => ['guia.pdf', "Esto es solo texto\n", 'La extensión del archivo no corresponde a lo que contiene. Guárdalo de nuevo con su formato real.'],
        'un DOCX' => ['guia.docx', Documents::docx(), 'Solo se admiten documentos PDF, TXT o Markdown.'],
        'un ejecutable' => ['guia.exe', "MZ\x90\x00", 'Solo se admiten documentos PDF, TXT o Markdown.'],
        'un HTML' => ['guia.html', '<html></html>', 'Solo se admiten documentos PDF, TXT o Markdown.'],
        'sin extensión' => ['guia', 'texto', 'Solo se admiten documentos PDF, TXT o Markdown.'],
        'un vacío' => ['guia.txt', '', 'El archivo está vacío.'],
        'un texto con bytes nulos' => ['guia.txt', "hola\0mundo", 'La extensión del archivo no corresponde a lo que contiene. Guárdalo de nuevo con su formato real.'],
        'un texto que no es UTF-8' => ['guia.txt', "Env\xEDo r\xE1pido", 'Este archivo de texto no está en UTF-8. Guárdalo de nuevo con esa codificación.'],
        'un PDF sin cabecera' => ['guia.pdf', 'sin cabecera', 'La extensión del archivo no corresponde a lo que contiene. Guárdalo de nuevo con su formato real.'],
    ]);

    it('rechaza lo que pesa más del máximo diciendo cuánto pesa y cuánto se admite', function (): void {
        config(['knowledge.documents.max_bytes' => 1024]);
        $tenant = ticketTenant();

        uploadDocument($this, kbAdmin($tenant), 'grande.txt', str_repeat('a', 2048))->assertSessionHasErrors(['file' => 'Este archivo pesa 0,0 MB; el máximo es 0,0 MB.']);

        expect(documentCount($tenant))->toBe(0);
    });

    it('sin archivo pide elegir uno', function (): void {
        $tenant = ticketTenant();

        $this->actingAs(kbAdmin($tenant))->post('/knowledge/documents', [])->assertSessionHasErrors(['file' => 'Elige un documento para subir.']);
    });

    it('limita las subidas a 10 por hora por persona y el límite es de cada una', function (): void {
        $tenant = ticketTenant();
        $admin = kbAdmin($tenant);
        $other = kbAdmin($tenant);
        RateLimiter::clear('knowledge-documents:'.$admin->id);
        RateLimiter::clear('knowledge-documents:'.$other->id);
        config(['knowledge.documents.uploads_per_hour' => 2]);

        uploadDocument($this, $admin, 'a.txt', 'uno')->assertSessionHasNoErrors();
        uploadDocument($this, $admin, 'b.txt', 'dos')->assertSessionHasNoErrors();
        uploadDocument($this, $admin, 'c.txt', 'tres')->assertSessionHasErrors('file');
        uploadDocument($this, $other, 'd.txt', 'cuatro')->assertSessionHasNoErrors();

        expect(documentCount($tenant))->toBe(3);
    });

    it('no pasa del tope de documentos por empresa y el tope es de cada empresa', function (): void {
        config(['knowledge.documents.max' => 1]);
        $tenant = ticketTenant();
        $other = ticketTenant();
        makeDocument($tenant);

        uploadDocument($this, kbAdmin($tenant), 'a.txt', 'texto')->assertSessionHasErrors(['file' => 'Ya tienes 1 documentos, el máximo por empresa. Elimina alguno para subir otro.']);
        uploadDocument($this, kbAdmin($other), 'a.txt', 'texto')->assertSessionHasNoErrors();

        expect(documentCount($tenant))->toBe(1)->and(documentCount($other))->toBe(1)
            ->and(Storage::disk(config()->string('files.disk'))->allFiles())->toHaveCount(1);
    });

    it('ignora lo que el cliente intente fijar: empresa, estado, autor, ruta', function (): void {
        $tenant = ticketTenant();
        $other = ticketTenant();
        $admin = kbAdmin($tenant);

        $this->actingAs($admin)->post('/knowledge/documents', ['file' => Documents::upload('a.txt', 'texto'), 'tenant_id' => $other->id, 'status' => 'published', 'created_by' => 99999, 'path' => '../../etc/passwd', 'body' => 'inyectado'])->assertSessionHasNoErrors();

        $document = app(TenantContext::class)->run($tenant, fn () => KbDocument::query()->firstOrFail());
        expect($document->status)->toBe(DocumentStatus::Scanning)->and($document->tenant_id)->toBe($tenant->id)->and($document->created_by)->toBe($admin->id)
            ->and($document->path)->toStartWith("tenants/{$tenant->id}/knowledge/")->and($document->body)->toBeNull()
            ->and(documentCount($other))->toBe(0);
    });
});

describe('quién puede qué con los documentos', function (): void {
    it('solo el administrador sube y escribe; el agente lee; el observador no entra', function (): void {
        $tenant = ticketTenant();
        $document = makeDocument($tenant);
        $users = [[kbAdmin($tenant), true, true], [ticketStaff($tenant, Role::Agent), true, false], [ticketStaff($tenant, Role::Observer), false, false]];

        foreach ($users as [$user, $reads, $writes]) {
            $this->actingAs($user);
            $this->get('/knowledge/documents')->assertStatus($reads ? 200 : 403);
            $this->get("/knowledge/documents/{$document->id}")->assertStatus($reads ? 200 : 403);
            $this->post('/knowledge/documents', ['file' => Documents::upload('a.txt', 'texto')])->assertStatus($writes ? 302 : 403);
            $this->put("/knowledge/documents/{$document->id}", ['title' => 'Otro'])->assertStatus($writes ? 302 : 403);
            $this->post("/knowledge/documents/{$document->id}/publish")->assertStatus($writes ? 302 : 403);
            $this->post("/knowledge/documents/{$document->id}/unpublish")->assertStatus($writes ? 302 : 403);
            $this->delete("/knowledge/documents/{$document->id}")->assertStatus($writes ? 302 : 403);
            $document = $writes ? makeDocument($tenant) : $document;
        }
    });

    it('un agente ve la pantalla del documento de solo lectura', function (): void {
        $tenant = ticketTenant();
        $document = makeDocument($tenant);

        $props = $this->actingAs(ticketStaff($tenant, Role::Agent))->get("/knowledge/documents/{$document->id}")->viewData('page')['props'];

        expect($props['can_edit'])->toBeFalse();
    });

    it('sin sesión pide iniciar sesión', function (): void {
        $this->get('/knowledge/documents')->assertRedirect('/login');
        $this->post('/knowledge/documents')->assertRedirect('/login');
    });
});

describe('aislamiento entre empresas con los documentos', function (): void {
    it('un documento de otra empresa da 404 por la interfaz y no se toca', function (): void {
        $mine = ticketTenant();
        $theirs = ticketTenant();
        $foreign = makeDocument($theirs, ['title' => 'Secreto de la otra empresa']);

        $this->actingAs(kbAdmin($mine));
        $this->get("/knowledge/documents/{$foreign->id}")->assertNotFound();
        $this->put("/knowledge/documents/{$foreign->id}", ['title' => 'Hackeado'])->assertNotFound();
        $this->post("/knowledge/documents/{$foreign->id}/publish")->assertNotFound();
        $this->post("/knowledge/documents/{$foreign->id}/unpublish")->assertNotFound();
        $this->delete("/knowledge/documents/{$foreign->id}")->assertNotFound();

        expect(documentFresh($theirs, $foreign->id)?->title)->toBe('Secreto de la otra empresa')
            ->and(AuditLog::query()->where('event', 'like', 'knowledge.document%')->count())->toBe(0);
    });

    it('la lista solo trae los documentos de la empresa', function (): void {
        $mine = ticketTenant();
        $theirs = ticketTenant();
        makeDocument($mine, ['title' => 'Mío']);
        makeDocument($theirs, ['title' => 'Ajeno']);

        $props = $this->actingAs(kbAdmin($mine))->get('/knowledge/documents')->viewData('page')['props'];

        expect(array_column($props['documents'], 'title'))->toBe(['Mío'])->and($props['total'])->toBe(1);
    });

    it('la lista no revela el motivo técnico de un fallo, solo su mensaje', function (): void {
        $tenant = ticketTenant();
        makeDocument($tenant, ['status' => DocumentStatus::Failed, 'failure' => DocumentFailure::PdfUnreadable, 'path' => null]);

        $props = $this->actingAs(kbAdmin($tenant))->get('/knowledge/documents')->viewData('page')['props'];

        expect($props['documents'][0]['failure'])->toBe('No pudimos leer este PDF. Guárdalo de nuevo desde su programa y vuelve a subirlo.')
            ->and(json_encode($props['documents']))->not->toContain('path')->not->toContain('sha256')->not->toContain('disk');
    });
});
