<?php

declare(strict_types=1);

namespace App\Files;

use App\Enums\AuditEvent;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\AuditLogger;
use App\Tenancy\TenancyViolation;
use App\Tenancy\TenantContext;
use ErrorException;
use finfo;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Único camino para guardar archivos de una empresa (RS-12). Nada de lo que declara el cliente se da por bueno: el
 * tipo sale del contenido, la imagen se decodifica y se vuelve a codificar (así desaparecen metadatos y cualquier
 * carga escondida tras los datos de la imagen) y el nombre y la ruta los genera el sistema.
 */
class FileStore
{
    /** Tipo real detectado → extensiones con las que puede llegar. */
    private const IMAGE_TYPES = [
        'image/png' => ['png'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/webp' => ['webp'],
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function storeImage(UploadedFile $upload, FilePurpose $purpose, User $uploader): StoredFile
    {
        $tenantId = $this->context->id();

        if ($tenantId === null) {
            throw TenancyViolation::missingContext(StoredFile::class);
        }

        $this->throttle($tenantId);

        $bytes = $this->read($upload);
        [$mime, $width, $height] = $this->inspect($bytes, $upload->getClientOriginalExtension());
        $png = $this->reencode($bytes);

        // En minúsculas, como los genera el propio modelo: la ruta y el identificador de la URL deben coincidir.
        $id = strtolower((string) Str::ulid());
        $disk = config()->string('files.disk');
        $path = "tenants/{$tenantId}/{$purpose->value}/{$id}.png";

        if (! Storage::disk($disk)->put($path, $png)) {
            throw new RuntimeException('No se pudo guardar el archivo en el disco configurado.');
        }

        try {
            $file = new StoredFile;
            $file->forceFill([
                'id' => $id,
                'purpose' => $purpose,
                'disk' => $disk,
                'path' => $path,
                'mime' => 'image/png',
                'size' => strlen($png),
                'width' => $width,
                'height' => $height,
                'sha256' => hash('sha256', $png),
                'uploaded_by' => $uploader->id,
            ])->save();
        } catch (Throwable $failure) {
            // Sin su fila el archivo quedaría huérfano en el disco.
            Storage::disk($disk)->delete($path);

            throw $failure;
        }

        $this->audit->record(AuditEvent::FileUploaded, $uploader->id, ['file_id' => $id, 'purpose' => $purpose->value]);

        return $file;
    }

    public function delete(StoredFile $file, ?User $actor = null): void
    {
        Storage::disk($file->disk)->delete($file->path);
        $file->delete();

        $this->audit->record(AuditEvent::FileDeleted, $actor?->id, ['file_id' => $file->id, 'purpose' => $file->purpose->value]);
    }

    /**
     * Enlace de descarga que caduca. Además de la firma, la ruta exige sesión y la empresa del archivo.
     *
     * La caducidad se redondea a ventanas fijas (entre una y dos veces el tiempo configurado), de modo que dentro de
     * una ventana el enlace es el mismo y el navegador puede reutilizar la imagen en vez de pedirla en cada página.
     */
    public function temporaryUrl(StoredFile $file): string
    {
        $window = config()->integer('files.url_ttl_minutes') * 60;
        $expires = Carbon::createFromTimestamp(((int) floor(time() / $window) + 2) * $window);

        return URL::temporarySignedRoute('files.show', $expires, ['file' => $file->id]);
    }

    private function throttle(int $tenantId): void
    {
        $key = 'file-uploads:'.$tenantId;
        $limit = config()->integer('files.uploads_per_hour');

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            throw FileRejected::because('rate_limited', ['minutes' => max(1, (int) ceil(RateLimiter::availableIn($key) / 60))]);
        }

        RateLimiter::hit($key, 3600);
    }

    private function read(UploadedFile $upload): string
    {
        if (! $upload->isValid()) {
            throw FileRejected::because('upload_failed');
        }

        $size = (int) $upload->getSize();
        $max = config()->integer('files.image.max_bytes');

        if ($size === 0) {
            throw FileRejected::because('empty');
        }

        if ($size > $max) {
            throw FileRejected::because('too_large', ['size' => $this->megabytes($size), 'max' => $this->megabytes($max)]);
        }

        $bytes = file_get_contents((string) $upload->getRealPath());

        if ($bytes === false) {
            throw FileRejected::because('upload_failed');
        }

        return $bytes;
    }

    /**
     * @return array{0: string, 1: int, 2: int}
     */
    private function inspect(string $bytes, string $clientExtension): array
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if (! is_string($mime) || ! array_key_exists($mime, self::IMAGE_TYPES)) {
            throw FileRejected::because('unsupported_type');
        }

        if (! in_array(mb_strtolower($clientExtension), self::IMAGE_TYPES[$mime], true)) {
            throw FileRejected::because('extension_mismatch');
        }

        try {
            $info = getimagesizefromstring($bytes);
        } catch (ErrorException) {
            throw FileRejected::because('corrupt');
        }

        if ($info === false || $info['mime'] !== $mime) {
            throw FileRejected::because('corrupt');
        }

        [$width, $height] = $info;
        $min = config()->integer('files.image.min_side');
        $max = config()->integer('files.image.max_side');

        if ($width > $max || $height > $max) {
            throw FileRejected::because('dimensions_large', ['width' => $width, 'height' => $height, 'max' => $max]);
        }

        if ($width < $min || $height < $min) {
            throw FileRejected::because('dimensions_small', ['width' => $width, 'height' => $height, 'min' => $min]);
        }

        if ($this->isAnimated($mime, $bytes)) {
            throw FileRejected::because('animated');
        }

        return [$mime, $width, $height];
    }

    private function isAnimated(string $mime, string $bytes): bool
    {
        if ($mime === 'image/webp') {
            return str_contains($bytes, 'ANIM');
        }

        if ($mime === 'image/png') {
            $firstData = strpos($bytes, 'IDAT');

            return str_contains($firstData === false ? $bytes : substr($bytes, 0, $firstData), 'acTL');
        }

        return false;
    }

    private function reencode(string $bytes): string
    {
        try {
            $image = imagecreatefromstring($bytes);
        } catch (ErrorException) {
            throw FileRejected::because('corrupt');
        }

        if (! $image instanceof GdImage) {
            throw FileRejected::because('corrupt');
        }

        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();
        $written = imagepng($image, null, 6);
        $png = (string) ob_get_clean();

        if (! $written || $png === '') {
            throw FileRejected::because('corrupt');
        }

        return $png;
    }

    private function megabytes(int $bytes): string
    {
        return number_format($bytes / 1048576, 1, ',', '.');
    }
}
