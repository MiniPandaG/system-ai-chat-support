<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Enums\Role;
use App\Files\FilePurpose;
use App\Files\FileRejected;
use App\Files\FileStore;
use App\Models\AuditLog;
use App\Models\StoredFile;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenancyViolation;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Images;

beforeEach(function (): void {
    Storage::fake('local');
    $this->tenant = Tenant::factory()->create();
    $this->uploader = User::factory()->forTenant($this->tenant, Role::Admin)->create();
    $this->store = app(FileStore::class);
    $this->context = app(TenantContext::class);
});

/** Sube dentro de la empresa y devuelve el archivo guardado. */
function storeLogo(string $name, string $content, ?Tenant $tenant = null): StoredFile
{
    $tenant ??= test()->tenant;

    return test()->context->run($tenant, fn (): StoredFile => test()->store->storeImage(Images::upload($name, $content), FilePurpose::Logo, test()->uploader));
}

it('guarda una imagen válida como PNG, con nombre y ruta generados por el sistema', function (string $name, string $content): void {
    $file = storeLogo($name, $content);

    expect($file->path)->toMatch('#^tenants/'.$this->tenant->id.'/logo/[0-9a-z]{26}\.png$#')
        ->and($file->id)->toHaveLength(26)
        ->and($file->tenant_id)->toBe($this->tenant->id)
        ->and($file->mime)->toBe('image/png')
        ->and($file->width)->toBe(128)
        ->and($file->height)->toBe(128)
        ->and($file->uploaded_by)->toBe($this->uploader->id)
        ->and($file->purpose)->toBe(FilePurpose::Logo);

    $stored = Storage::disk('local')->get($file->path);

    expect(finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), (string) $stored))->toBe('image/png')
        ->and(hash('sha256', (string) $stored))->toBe($file->sha256)
        ->and(strlen((string) $stored))->toBe($file->size);
})->with([
    'PNG' => ['logo.png', Images::png()],
    'JPEG' => ['logo.jpg', Images::jpeg()],
    'JPEG con extensión larga' => ['logo.JPEG', Images::jpeg()],
    'WebP' => ['logo.webp', Images::webp()],
]);

it('un nombre hostil no llega a la ruta ni a la base', function (): void {
    $file = storeLogo('../../etc/passwd.png', Images::png());

    expect($file->path)->not->toContain('passwd')->and($file->path)->not->toContain('..')
        ->and(json_encode($file->getAttributes()))->not->toContain('passwd');
});

it('rechaza lo que no es una imagen admitida', function (string $name, string $content, string $reason): void {
    expect(fn () => storeLogo($name, $content))->toThrow(FileRejected::class, __('files.rejected.'.$reason));

    expect(Storage::disk('local')->allFiles())->toBe([])
        ->and($this->context->run($this->tenant, fn (): int => StoredFile::query()->count()))->toBe(0);
})->with([
    'SVG con script' => ['logo.svg', Images::svg(), 'unsupported_type'],
    'SVG disfrazado de PNG' => ['logo.png', Images::svg(), 'unsupported_type'],
    'código PHP disfrazado de PNG' => ['logo.png', '<?php system($_GET["c"]); ?>', 'unsupported_type'],
    'HTML' => ['logo.png', '<html><script>alert(1)</script></html>', 'unsupported_type'],
    'texto con extensión de imagen' => ['logo.png', 'esto no es una imagen', 'unsupported_type'],
    'ejecutable de Windows' => ['logo.png', "MZ\x90\x00\x03\x00\x00\x00".str_repeat("\x00", 200), 'unsupported_type'],
    'PDF' => ['logo.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n", 'unsupported_type'],
    'ZIP' => ['logo.png', "PK\x03\x04".str_repeat("\x00", 100), 'unsupported_type'],
    'GIF' => ['logo.gif', Images::gif(), 'unsupported_type'],
    'archivo vacío' => ['logo.png', '', 'empty'],
]);

it('rechaza una extensión que no corresponde al contenido', function (string $name, string $content): void {
    expect(fn () => storeLogo($name, $content))->toThrow(FileRejected::class, __('files.rejected.extension_mismatch'));
})->with([
    'PNG con extensión JPG' => ['logo.jpg', Images::png()],
    'JPEG con extensión PNG' => ['logo.png', Images::jpeg()],
    'PNG con extensión PHP' => ['logo.php', Images::png()],
    'PNG sin extensión' => ['logo', Images::png()],
    'doble extensión' => ['logo.php.png.exe', Images::png()],
]);

it('un PNG con código escondido tras sus datos se guarda limpio: se vuelve a codificar', function (): void {
    $file = storeLogo('logo.png', Images::pngWithPayload());
    $stored = (string) Storage::disk('local')->get($file->path);

    expect($stored)->not->toContain('<?php')->and($stored)->not->toContain('system(');
});

it('rechaza archivos que superan el máximo con el tamaño exacto en el mensaje', function (): void {
    $big = Images::png().str_repeat('a', 2 * 1024 * 1024);

    expect(fn () => storeLogo('logo.png', $big))->toThrow(FileRejected::class, 'Este archivo pesa 2,0 MB; el máximo es 2,0 MB.');
});

it('rechaza imágenes demasiado grandes o pequeñas sin llegar a decodificarlas', function (string $content, string $message): void {
    expect(fn () => storeLogo('logo.png', $content))->toThrow(FileRejected::class, $message);
})->with([
    'bomba de descompresión de 60 000 px' => [Images::pngClaiming(60000, 60000), 'La imagen mide 60000×60000 px; el máximo es 2048×2048 px.'],
    'más de 2048 px de ancho' => [Images::png(2049, 64), 'La imagen mide 2049×64 px; el máximo es 2048×2048 px.'],
    'menos de 64 px' => [Images::png(32, 32), 'La imagen mide 32×32 px; el mínimo es 64×64 px.'],
]);

it('rechaza imágenes animadas', function (): void {
    expect(fn () => storeLogo('logo.png', Images::animatedPng()))->toThrow(FileRejected::class, __('files.rejected.animated'));
});

it('rechaza imágenes cuyo contenido está dañado', function (): void {
    $truncated = substr(Images::png(), 0, 60);

    expect(fn () => storeLogo('logo.png', $truncated))->toThrow(FileRejected::class);
});

it('los identificadores no son secuenciales ni se repiten', function (): void {
    $first = storeLogo('a.png', Images::png());
    $second = storeLogo('b.png', Images::png());

    expect($first->id)->not->toBe($second->id)->and($first->path)->not->toBe($second->path);
});

it('el archivo de una empresa no existe para otra', function (): void {
    $file = storeLogo('logo.png', Images::png());
    $other = Tenant::factory()->create();

    $seen = $this->context->run($other, fn (): array => [
        StoredFile::query()->count(),
        StoredFile::query()->find($file->id),
    ]);

    expect($seen[0])->toBe(0)->and($seen[1])->toBeNull();
});

it('no guarda nada sin una empresa en contexto', function (): void {
    expect(fn () => $this->store->storeImage(Images::upload('logo.png', Images::png()), FilePurpose::Logo, $this->uploader))
        ->toThrow(TenancyViolation::class);

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('limita las subidas por hora y por empresa', function (): void {
    RateLimiter::clear('file-uploads:'.$this->tenant->id);

    for ($i = 0; $i < 10; $i++) {
        storeLogo("logo{$i}.png", Images::png());
    }

    expect(fn () => storeLogo('otro.png', Images::png()))->toThrow(FileRejected::class, 'Subiste demasiados archivos.');

    $other = Tenant::factory()->create();
    expect(storeLogo('logo.png', Images::png(), $other)->tenant_id)->toBe($other->id);
});

it('borrar quita el archivo del disco y de la base, y queda auditado sin el contenido', function (): void {
    $file = storeLogo('logo.png', Images::png());

    $this->context->run($this->tenant, fn () => $this->store->delete($file, $this->uploader));

    expect(Storage::disk('local')->exists($file->path))->toBeFalse()
        ->and($this->context->run($this->tenant, fn (): int => StoredFile::query()->count()))->toBe(0);

    $dump = (string) json_encode(AuditLog::query()->whereIn('event', [AuditEvent::FileUploaded->value, AuditEvent::FileDeleted->value])->get()->map->getAttributes());

    expect($dump)->toContain($file->id)->and($dump)->not->toContain($file->path)->and($dump)->not->toContain('logo.png');
});

it('el disco de archivos es privado: no está bajo la carpeta pública ni se sirve por URL', function (): void {
    $disk = config('filesystems.disks.'.config('files.disk'));

    expect(config('files.disk'))->not->toBe('public')
        ->and($disk['serve'] ?? null)->toBeFalse()
        ->and($disk['root'])->not->toContain('public');
});
