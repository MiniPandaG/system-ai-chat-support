<?php

declare(strict_types=1);

namespace App\Attachments;

use App\Files\FileRejected;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\Models\User;
use App\Tenancy\TenancyViolation;
use App\Tenancy\TenantContext;
use finfo;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Único camino para guardar adjuntos de tickets (RS-12). Nada de lo que declara el navegador se da por bueno: el tipo
 * sale del contenido y debe coincidir con la extensión, el peso se comprueba, y el nombre y la ruta los genera el
 * sistema. El archivo se guarda tal cual (no se re-codifica): por eso nace «por revisar», nunca se muestra dentro de
 * la página y solo se entrega como descarga después de que el antivirus lo declare limpio.
 */
class AttachmentStore
{
    /** Tipos de Office: su ZIP debe tener esta carpeta y no puede llevar macros. */
    private const OFFICE = [
        'docx' => ['mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'folder' => 'word/'],
        'xlsx' => ['mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'folder' => 'xl/'],
    ];

    /** Un ZIP legítimo de Office descomprime a mucho menos que esto; más es una bomba de compresión. */
    private const MAX_UNCOMPRESSED_BYTES = 200 * 1024 * 1024;

    private const MAX_ZIP_ENTRIES = 5000;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Comprueba todos los archivos antes de guardar ninguno. Un archivo no válido lanza `FileRejected` con un mensaje
     * que ya dice qué hacer.
     *
     * @param  list<UploadedFile>  $uploads
     * @return list<PreparedAttachment>
     *
     * @throws FileRejected
     */
    public function prepare(array $uploads, User $uploader): array
    {
        if (count($uploads) > config()->integer('attachments.max_per_message')) {
            throw FileRejected::because('attachments_too_many', ['max' => config()->integer('attachments.max_per_message')]);
        }

        $this->throttle($uploader, count($uploads));

        $prepared = [];

        foreach ($uploads as $upload) {
            try {
                $prepared[] = $this->inspect($upload);
            } catch (FileRejected $rejected) {
                // Con varios archivos hay que decir cuál falló.
                throw new FileRejected('«'.$this->displayName($upload).'»: '.$rejected->getMessage(), previous: $rejected);
            }
        }

        return $prepared;
    }

    /**
     * Comprueba un archivo que trae un canal (un correo entrante): igual que `prepare`, pero sin persona a quien cobrar el
     * límite por hora (cada canal pone el suyo) y de uno en uno, para que un archivo malo no pierda a los demás.
     *
     * @throws FileRejected
     */
    public function prepareFromChannel(UploadedFile $upload): PreparedAttachment
    {
        try {
            return $this->inspect($upload);
        } catch (FileRejected $rejected) {
            throw new FileRejected('«'.$this->displayName($upload).'»: '.$rejected->getMessage(), previous: $rejected);
        }
    }

    /**
     * Guarda un archivo ya comprobado en el disco privado y crea su fila, «por revisar».
     *
     * @throws Throwable
     */
    public function store(TicketMessage $message, PreparedAttachment $file, ?User $uploader): TicketAttachment
    {
        $tenantId = $this->context->id();

        if ($tenantId === null) {
            throw TenancyViolation::missingContext(TicketAttachment::class);
        }

        $id = strtolower((string) Str::ulid());
        $disk = config()->string('files.disk');
        $path = "tenants/{$tenantId}/attachments/{$id}";

        if (Storage::disk($disk)->putFileAs("tenants/{$tenantId}/attachments", new File((string) $file->upload->getRealPath()), $id) === false) {
            throw new RuntimeException('No se pudo guardar el adjunto en el disco configurado.');
        }

        try {
            $attachment = new TicketAttachment;
            $attachment->forceFill([
                'id' => $id,
                'ticket_id' => $message->ticket_id,
                'ticket_message_id' => $message->id,
                'uploaded_by' => $uploader?->id,
                'original_name' => $file->name,
                'mime' => $file->mime,
                'size' => $file->size,
                'sha256' => $file->sha256,
                'disk' => $disk,
                'path' => $path,
                'status' => AttachmentStatus::Pending,
            ])->save();
        } catch (Throwable $failure) {
            // Sin su fila el archivo quedaría huérfano en el disco.
            Storage::disk($disk)->delete($path);

            throw $failure;
        }

        return $attachment;
    }

    /** Borra el archivo del disco (por ejemplo si el mensaje no llegó a guardarse). */
    public function discard(TicketAttachment $attachment): void
    {
        if ($attachment->path !== null) {
            Storage::disk($attachment->disk)->delete($attachment->path);
        }
    }

    /**
     * Enlace de descarga que caduca. Además de la firma, la ruta exige sesión y la empresa del archivo, y solo se
     * entrega si el archivo ya salió limpio.
     */
    public function downloadUrl(TicketAttachment $attachment): ?string
    {
        if (! $attachment->isDownloadable()) {
            return null;
        }

        $window = config()->integer('attachments.url_ttl_minutes') * 60;
        $expires = Carbon::createFromTimestamp(((int) floor(time() / $window) + 2) * $window);

        return URL::temporarySignedRoute('attachments.download', $expires, ['attachment' => $attachment->id]);
    }

    private function throttle(User $uploader, int $count): void
    {
        if ($count === 0) {
            return;
        }

        $key = 'attachments:'.$uploader->id;
        $limit = config()->integer('attachments.uploads_per_hour');

        if (RateLimiter::remaining($key, $limit) < $count) {
            // «Vuelve a intentarlo en N minutos»: lo que falta para que expire la ventana de una hora.
            throw FileRejected::because('rate_limited', ['minutes' => max(1, (int) ceil(RateLimiter::availableIn($key) / 60))]);
        }

        for ($hit = 0; $hit < $count; $hit++) {
            RateLimiter::hit($key, 3600);
        }
    }

    private function inspect(UploadedFile $upload): PreparedAttachment
    {
        if (! $upload->isValid()) {
            throw FileRejected::because('upload_failed');
        }

        $path = (string) $upload->getRealPath();
        $size = (int) $upload->getSize();
        $max = config()->integer('attachments.max_bytes');

        if ($size === 0) {
            throw FileRejected::because('empty');
        }

        if ($size > $max) {
            throw FileRejected::because('too_large', ['size' => $this->megabytes($size), 'max' => $this->megabytes($max)]);
        }

        $extension = mb_strtolower($upload->getClientOriginalExtension());
        $allowed = $this->allowedTypes();

        if (! array_key_exists($extension, $allowed)) {
            throw FileRejected::because('attachment_type');
        }

        $detected = (new finfo(FILEINFO_MIME_TYPE))->file($path);

        if (! is_string($detected) || ! in_array($detected, $allowed[$extension], true)) {
            // Lo que contiene no es lo que dice la extensión (por ejemplo un ejecutable con extensión .pdf).
            throw FileRejected::because($this->isKnownType($detected, $allowed) ? 'extension_mismatch' : 'attachment_type');
        }

        $mime = $this->verifyContent($extension, $detected, $path);
        $hash = hash_file('sha256', $path);

        if ($hash === false) {
            throw FileRejected::because('upload_failed');
        }

        return new PreparedAttachment($upload, $mime, $this->cleanName($upload->getClientOriginalName(), $extension), $size, $hash);
    }

    /**
     * Comprobaciones propias de cada formato, más allá del tipo detectado. Devuelve el tipo que se guarda.
     */
    private function verifyContent(string $extension, string $detected, string $path): string
    {
        if (isset(self::OFFICE[$extension])) {
            $this->verifyOffice($extension, $path);

            return self::OFFICE[$extension]['mime'];
        }

        if ($extension === 'pdf' && file_get_contents($path, length: 5) !== '%PDF-') {
            throw FileRejected::because('attachment_corrupt');
        }

        if (($extension === 'txt' || $extension === 'csv') && $this->hasNullByte($path)) {
            // Un texto de verdad no lleva bytes nulos: es un binario disfrazado.
            throw FileRejected::because('attachment_text_invalid');
        }

        return $extension === 'txt' || $extension === 'csv' ? ($extension === 'csv' ? 'text/csv' : 'text/plain') : $detected;
    }

    private function verifyOffice(string $extension, string $path): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw FileRejected::because('attachment_office_invalid');
        }

        try {
            $total = 0;
            $hasFolder = false;
            $hasContentTypes = false;

            if ($zip->numFiles > self::MAX_ZIP_ENTRIES) {
                throw FileRejected::because('attachment_office_invalid');
            }

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);

                if ($entry === false) {
                    throw FileRejected::because('attachment_office_invalid');
                }

                $name = $entry['name'];
                $total += $entry['size'];

                if ($total > self::MAX_UNCOMPRESSED_BYTES) {
                    throw FileRejected::because('attachment_office_invalid');
                }

                // Rutas que salen de la carpeta del documento y archivos de macros: ni se admiten ni hacen falta.
                if (str_contains($name, '..') || str_starts_with($name, '/') || stripos($name, 'vbaProject') !== false) {
                    throw FileRejected::because('attachment_office_macros');
                }

                $hasContentTypes = $hasContentTypes || $name === '[Content_Types].xml';
                $hasFolder = $hasFolder || str_starts_with($name, self::OFFICE[$extension]['folder']);
            }

            if (! $hasContentTypes || ! $hasFolder) {
                throw FileRejected::because('attachment_office_invalid');
            }
        } finally {
            $zip->close();
        }
    }

    private function hasNullByte(string $path): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw FileRejected::because('upload_failed');
        }

        try {
            while (! feof($handle)) {
                $chunk = fread($handle, 65536);

                if ($chunk === false) {
                    throw FileRejected::because('upload_failed');
                }

                if (str_contains($chunk, "\0")) {
                    return true;
                }
            }
        } finally {
            fclose($handle);
        }

        return false;
    }

    /**
     * Extensión → tipos reales admitidos, tal como los declara `config/attachments.php`.
     *
     * @return array<string, list<string>>
     */
    private function allowedTypes(): array
    {
        $types = [];

        foreach (config()->array('attachments.types') as $extension => $mimes) {
            if (is_string($extension) && is_array($mimes)) {
                $types[$extension] = array_values(array_filter($mimes, is_string(...)));
            }
        }

        return $types;
    }

    /**
     * @param  array<string, list<string>>  $allowed
     */
    private function isKnownType(mixed $detected, array $allowed): bool
    {
        foreach ($allowed as $types) {
            if (in_array($detected, $types, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Nombre para mostrar y para la descarga: sin carpetas, sin caracteres de control ni de ruta, de hasta 100
     * caracteres y siempre con la extensión comprobada.
     */
    private function cleanName(string $original, string $extension): string
    {
        $base = pathinfo(str_replace('\\', '/', $original), PATHINFO_FILENAME);
        $base = (string) preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', ' ', $base);
        $base = trim((string) preg_replace('/\s+/u', ' ', $base), " .\t");
        $base = mb_substr($base, 0, 100);

        return ($base === '' ? 'archivo' : $base).'.'.$extension;
    }

    private function displayName(UploadedFile $upload): string
    {
        return $this->cleanName($upload->getClientOriginalName(), mb_strtolower($upload->getClientOriginalExtension()));
    }

    private function megabytes(int $bytes): string
    {
        return number_format($bytes / 1048576, 1, ',', '.');
    }
}
