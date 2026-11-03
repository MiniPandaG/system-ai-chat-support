<?php

declare(strict_types=1);

namespace App\Knowledge;

use App\Files\FileRejected;
use App\Models\KbDocument;
use App\Models\User;
use App\Tenancy\TenancyViolation;
use App\Tenancy\TenantContext;
use finfo;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Único camino para guardar el archivo de un documento (RS-12). Nada de lo que declara el navegador se da por bueno: el
 * tipo sale del contenido y debe coincidir con la extensión, el peso se comprueba, y la ruta la genera el sistema. El
 * archivo nace «por revisar» con el antivirus y se borra del disco en cuanto se extrae su texto.
 */
class DocumentStore
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @throws FileRejected
     */
    public function prepare(UploadedFile $upload, User $uploader): PreparedDocument
    {
        $this->throttle($uploader);

        return $this->inspect($upload);
    }

    /**
     * Guarda el archivo en el disco privado y crea su fila, «por revisar».
     */
    public function store(PreparedDocument $file, User $uploader): KbDocument
    {
        $tenantId = $this->context->id();

        if ($tenantId === null) {
            throw TenancyViolation::missingContext(KbDocument::class);
        }

        $key = strtolower((string) Str::ulid());
        $disk = config()->string('files.disk');

        if (Storage::disk($disk)->putFileAs("tenants/{$tenantId}/knowledge", new File((string) $file->upload->getRealPath()), $key) === false) {
            throw new RuntimeException('No se pudo guardar el documento en el disco configurado.');
        }

        $document = new KbDocument;
        $document->forceFill([
            'title' => $this->defaultTitle($file->name),
            'original_name' => $file->name,
            'type' => $file->type,
            'size_bytes' => $file->size,
            'sha256' => $file->sha256,
            'status' => DocumentStatus::Scanning,
            'disk' => $disk,
            'path' => "tenants/{$tenantId}/knowledge/{$key}",
            'created_by' => $uploader->id,
            'updated_by' => $uploader->id,
        ])->save();

        return $document;
    }

    /** Borra el archivo del disco si todavía está; nunca falla si ya no existe. */
    public function discard(KbDocument $document): void
    {
        if ($document->path !== null) {
            Storage::disk($document->disk)->delete($document->path);
        }
    }

    private function inspect(UploadedFile $upload): PreparedDocument
    {
        if (! $upload->isValid()) {
            throw FileRejected::because('upload_failed');
        }

        $path = (string) $upload->getRealPath();
        $size = (int) $upload->getSize();
        $max = config()->integer('knowledge.documents.max_bytes');

        if ($size === 0) {
            throw FileRejected::because('empty');
        }

        if ($size > $max) {
            throw FileRejected::because('too_large', ['size' => $this->megabytes($size), 'max' => $this->megabytes($max)]);
        }

        $extension = mb_strtolower($upload->getClientOriginalExtension());
        $allowed = $this->allowedTypes();

        if (! array_key_exists($extension, $allowed)) {
            throw FileRejected::because('knowledge_type');
        }

        $detected = (new finfo(FILEINFO_MIME_TYPE))->file($path);

        if (! is_string($detected) || ! in_array($detected, $allowed[$extension], true)) {
            // Lo que contiene no es lo que dice la extensión (por ejemplo un ejecutable llamado «guia.pdf»).
            throw FileRejected::because('extension_mismatch');
        }

        if ($extension === 'pdf') {
            if (file_get_contents($path, false, null, 0, 5) !== '%PDF-') {
                throw FileRejected::because('knowledge_corrupt');
            }
        } else {
            $this->verifyText($path);
        }

        return new PreparedDocument($upload, $this->displayName($upload), $extension, $size, (string) hash_file('sha256', $path));
    }

    /** Un texto de verdad: UTF-8 válido y sin bytes nulos. */
    private function verifyText(string $path): void
    {
        $content = (string) file_get_contents($path);

        if (str_contains($content, "\0")) {
            throw FileRejected::because('knowledge_text_invalid');
        }

        if (! mb_check_encoding($content, 'UTF-8')) {
            throw FileRejected::because('knowledge_text_encoding');
        }
    }

    private function throttle(User $uploader): void
    {
        $key = 'knowledge-documents:'.$uploader->id;

        if (RateLimiter::remaining($key, config()->integer('knowledge.documents.uploads_per_hour')) < 1) {
            throw FileRejected::because('rate_limited', ['minutes' => max(1, (int) ceil(RateLimiter::availableIn($key) / 60))]);
        }

        RateLimiter::hit($key, 3600);
    }

    /** El nombre que subió la persona, solo para mostrarlo: sin carpetas, sin caracteres de control y corto. */
    private function displayName(UploadedFile $upload): string
    {
        $name = TextCleaner::line(basename(str_replace('\\', '/', $upload->getClientOriginalName())));
        $name = mb_substr($name, 0, 100);

        return $name === '' ? 'documento' : $name;
    }

    private function defaultTitle(string $name): string
    {
        $title = TextCleaner::line((string) preg_replace('/\.[^.]*$/', '', $name));

        return mb_substr($title === '' ? 'Documento sin título' : $title, 0, config()->integer('knowledge.documents.title_max'));
    }

    private function megabytes(int $bytes): string
    {
        return number_format($bytes / 1024 / 1024, 1, ',', '.');
    }

    /**
     * @return array<string, list<string>>
     */
    private function allowedTypes(): array
    {
        $types = [];

        foreach (config()->array('knowledge.documents.types') as $extension => $mimes) {
            if (is_string($extension) && is_array($mimes)) {
                $types[$extension] = array_values(array_filter($mimes, is_string(...)));
            }
        }

        return $types;
    }
}
