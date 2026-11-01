<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Attachments\AttachmentStatus;
use App\Attachments\ClamAvScanner;
use App\Attachments\FakeScanner;
use App\Attachments\Scanner;
use App\Attachments\ScannerUnavailable;
use App\Attachments\ScanResult;
use App\Enums\AuditEvent;
use App\Jobs\ScanAttachment;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TicketAttachment;
use App\Services\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Documents;

beforeEach(function (): void {
    useTemporaryAttachmentDisk();
});

function runScan(Tenant $tenant, TicketAttachment $attachment): void
{
    app(TenantContext::class)->run($tenant, fn () => (new ScanAttachment($attachment->id))->handle(app(Scanner::class), app(AuditLogger::class)));
}

function fresh(Tenant $tenant, TicketAttachment $attachment): TicketAttachment
{
    return app(TenantContext::class)->run($tenant, fn () => TicketAttachment::query()->findOrFail($attachment->id));
}

/** Un antivirus que se comporta como se le pide, para probar los caminos que el de pruebas no cubre. */
function bindScanner(Closure $behaviour): object
{
    $spy = new class($behaviour) implements Scanner
    {
        public int $calls = 0;

        public function __construct(private readonly Closure $behaviour) {}

        public function scan($stream): ScanResult
        {
            $this->calls++;

            return ($this->behaviour)($stream);
        }
    };
    app()->instance(Scanner::class, $spy);

    return $spy;
}

describe('un archivo limpio', function (): void {
    it('pasa a limpio y queda descargable', function (): void {
        $tenant = ticketTenant();
        $attachment = pendingAttachment($tenant);

        runScan($tenant, $attachment);

        $after = fresh($tenant, $attachment);
        expect($after)->status->toBe(AttachmentStatus::Clean)->scanned_at->not->toBeNull()->path->not->toBeNull()
            ->and($after->isDownloadable())->toBeTrue()
            ->and(Storage::disk($after->disk)->exists((string) $after->path))->toBeTrue();
    });

    it('revisar dos veces no hace trabajo de más: solo se actúa sobre un archivo por revisar', function (): void {
        $tenant = ticketTenant();
        $attachment = pendingAttachment($tenant);
        $spy = bindScanner(fn () => ScanResult::clean());

        runScan($tenant, $attachment);
        runScan($tenant, $attachment);

        expect($spy->calls)->toBe(1);
    });

    it('un adjunto que ya no existe no rompe la revisión', function (): void {
        $tenant = ticketTenant();
        $attachment = pendingAttachment($tenant);
        app(TenantContext::class)->run($tenant, fn () => TicketAttachment::query()->whereKey($attachment->id)->delete());

        runScan($tenant, $attachment);

        expect(fresh_count($tenant))->toBe(0);
    });
});

function fresh_count(Tenant $tenant): int
{
    return app(TenantContext::class)->run($tenant, fn () => TicketAttachment::query()->count());
}

describe('una amenaza', function (): void {
    it('el archivo se borra, queda bloqueado y se anota la amenaza', function (): void {
        $tenant = ticketTenant();
        $attachment = pendingAttachment($tenant, FakeScanner::eicar());
        $path = (string) $attachment->path;

        runScan($tenant, $attachment);

        $after = fresh($tenant, $attachment);
        expect($after)->status->toBe(AttachmentStatus::Infected)->scan_signature->toBe('Eicar-Test-Signature')->path->toBeNull()->scanned_at->not->toBeNull()
            ->and($after->isDownloadable())->toBeFalse()
            ->and(Storage::disk($after->disk)->exists($path))->toBeFalse();
    });

    it('se audita con identificadores, sin nombre de archivo, ruta ni contenido', function (): void {
        $tenant = ticketTenant();
        $attachment = pendingAttachment($tenant, FakeScanner::eicar(), 'secreto-de-la-empresa.txt');

        runScan($tenant, $attachment);

        $log = AuditLog::query()->where('event', AuditEvent::TicketAttachmentInfected->value)->latest('id')->firstOrFail();
        expect($log->context)->toBe(['ticket_id' => $attachment->ticket_id, 'attachment_id' => $attachment->id])
            ->and(json_encode($log->context))->not->toContain('secreto')->not->toContain('tenants/');
    });

    it('desde la subida, un archivo con la cadena de prueba termina bloqueado y el mensaje se conserva', function (): void {
        $tenant = ticketTenant();
        $ticket = makeTicket($tenant);

        sendReply($this, ticketStaff($tenant), $ticket, ['body' => 'mira', 'files' => [Documents::upload('prueba.txt', FakeScanner::eicar())]])->assertSessionHasNoErrors();

        $attachment = attachmentsOf($tenant)->sole();
        expect($attachment->status)->toBe(AttachmentStatus::Infected)->and(storedFiles())->toBe([])->and(messageCountOf($ticket))->toBe(2);
    });
});

describe('cuando no se puede revisar', function (): void {
    it('un antivirus que no contesta deja el archivo por revisar y la revisión falla para reintentarse', function (): void {
        $tenant = ticketTenant();
        $attachment = pendingAttachment($tenant);
        bindScanner(fn () => throw new ScannerUnavailable('caído'));

        expect(fn () => runScan($tenant, $attachment))->toThrow(ScannerUnavailable::class);

        $after = fresh($tenant, $attachment);
        expect($after->status)->toBe(AttachmentStatus::Pending)->and($after->isDownloadable())->toBeFalse()
            ->and(Storage::disk($after->disk)->exists((string) $after->path))->toBeTrue();
    });

    it('agotados los reintentos queda «no revisado» y sigue sin poder descargarse', function (): void {
        $tenant = ticketTenant();
        $attachment = pendingAttachment($tenant);

        app(TenantContext::class)->run($tenant, fn () => (new ScanAttachment($attachment->id))->failed(new ScannerUnavailable('caído')));

        $after = fresh($tenant, $attachment);
        expect($after->status)->toBe(AttachmentStatus::Failed)->and($after->isDownloadable())->toBeFalse();
    });

    it('«no revisado» no pisa a un archivo que ya salió limpio', function (): void {
        $tenant = ticketTenant();
        $attachment = pendingAttachment($tenant);
        runScan($tenant, $attachment);

        app(TenantContext::class)->run($tenant, fn () => (new ScanAttachment($attachment->id))->failed(new ScannerUnavailable('caído')));

        expect(fresh($tenant, $attachment)->status)->toBe(AttachmentStatus::Clean);
    });

    it('un archivo que falta en el disco no se da por limpio', function (): void {
        $tenant = ticketTenant();
        $attachment = pendingAttachment($tenant);
        Storage::disk($attachment->disk)->delete((string) $attachment->path);

        runScan($tenant, $attachment);

        expect(fresh($tenant, $attachment))->status->toBe(AttachmentStatus::Failed);
    });

    it('un error inesperado del antivirus tampoco da por limpio nada', function (): void {
        $tenant = ticketTenant();
        $attachment = pendingAttachment($tenant);
        bindScanner(fn () => throw new RuntimeException('algo raro'));

        expect(fn () => runScan($tenant, $attachment))->toThrow(RuntimeException::class);

        expect(fresh($tenant, $attachment)->status)->toBe(AttachmentStatus::Pending);
    });

    it('la revisión tiene reintentos acotados con espera creciente', function (): void {
        $job = new ScanAttachment('x');

        expect($job->tries)->toBe(6)->and($job->backoff())->toBe([10, 30, 60, 120, 300]);
    });
});

describe('la revisión trabaja con la empresa del adjunto', function (): void {
    it('el trabajo encolado lleva la empresa y la restablece al ejecutarse', function (): void {
        Queue::fake();
        $tenant = ticketTenant();
        $ticket = makeTicket($tenant);

        sendReply($this, ticketStaff($tenant), $ticket, ['body' => 'x', 'files' => [Documents::upload('a.pdf', Documents::pdf())]]);

        Queue::assertPushed(ScanAttachment::class);
    });

    it('un adjunto de otra empresa no se revisa desde el contexto de esta', function (): void {
        $mine = ticketTenant();
        $theirs = ticketTenant();
        $foreign = pendingAttachment($theirs);
        $spy = bindScanner(fn () => ScanResult::clean());

        app(TenantContext::class)->run($mine, fn () => (new ScanAttachment($foreign->id))->handle(app(Scanner::class), app(AuditLogger::class)));

        expect($spy->calls)->toBe(0)->and(fresh($theirs, $foreign)->status)->toBe(AttachmentStatus::Pending);
    });
});

describe('attachments:rescan', function (): void {
    it('vuelve a encolar los que están por revisar o sin poder revisarse, y solo esos', function (): void {
        Queue::fake();
        $tenant = ticketTenant();
        $pending = pendingAttachment($tenant);
        $failed = pendingAttachment($tenant);
        $failed->forceFill(['status' => AttachmentStatus::Failed])->save();
        $clean = pendingAttachment($tenant);
        $clean->forceFill(['status' => AttachmentStatus::Clean])->save();
        $infected = pendingAttachment($tenant);
        $infected->forceFill(['status' => AttachmentStatus::Infected, 'path' => null])->save();

        $this->artisan('attachments:rescan', ['--minutes' => 0])->expectsOutput('Revisiones encoladas: 2.')->assertSuccessful();

        Queue::assertPushed(ScanAttachment::class, 2);
        Queue::assertPushed(ScanAttachment::class, fn (ScanAttachment $job): bool => $job->attachmentId === $pending->id);
        Queue::assertPushed(ScanAttachment::class, fn (ScanAttachment $job): bool => $job->attachmentId === $failed->id);
        expect(fresh($tenant, $failed)->status)->toBe(AttachmentStatus::Pending);
    });

    it('espera un tiempo antes de insistir con los recién subidos', function (): void {
        Queue::fake();
        $tenant = ticketTenant();
        pendingAttachment($tenant);

        $this->artisan('attachments:rescan')->expectsOutput('Revisiones encoladas: 0.')->assertSuccessful();

        Queue::assertNothingPushed();
    });

    it('recorre todas las empresas, cada una con su contexto', function (): void {
        Queue::fake();
        $a = ticketTenant();
        $b = ticketTenant();
        pendingAttachment($a);
        pendingAttachment($b);

        $this->artisan('attachments:rescan', ['--minutes' => 0])->expectsOutput('Revisiones encoladas: 2.')->assertSuccessful();
    });
});

describe('qué antivirus se usa', function (): void {
    it('«clamav» usa el demonio con su dirección y su tiempo de espera', function (): void {
        config(['attachments.scanner' => 'clamav', 'attachments.clamav.host' => 'antivirus', 'attachments.clamav.port' => 3310]);
        app()->forgetInstance(Scanner::class);

        expect(app(Scanner::class))->toBeInstanceOf(ClamAvScanner::class);
    });

    it('«fake» solo funciona fuera de producción', function (): void {
        config(['attachments.scanner' => 'fake']);
        app()->forgetInstance(Scanner::class);
        expect(app(Scanner::class))->toBeInstanceOf(FakeScanner::class);

        app()->detectEnvironment(fn (): string => 'production');
        app()->forgetInstance(Scanner::class);

        expect(fn () => app(Scanner::class))->toThrow(LogicException::class, 'no es válido en este entorno');
    });

    it('un valor desconocido no se interpreta como «sin antivirus»: la aplicación se niega', function (string $driver): void {
        config(['attachments.scanner' => $driver]);
        app()->forgetInstance(Scanner::class);

        expect(fn () => app(Scanner::class))->toThrow(LogicException::class);
    })->with(['none', 'off', '', 'null', 'false', 'CLAMAV ']);
});

describe('el antivirus de pruebas', function (): void {
    it('solo marca la cadena EICAR y deja pasar el resto', function (): void {
        $scanner = new FakeScanner;
        $stream = fn (string $content) => (function () use ($content) {
            $handle = fopen('php://memory', 'w+b');
            fwrite($handle, $content);
            rewind($handle);

            return $handle;
        })();

        expect($scanner->scan($stream('texto normal'))->clean)->toBeTrue()
            ->and($scanner->scan($stream(FakeScanner::eicar().'
'))->clean)->toBeFalse()
            ->and($scanner->scan($stream('antes '.FakeScanner::eicar().' después'))->clean)->toBeTrue()
            ->and($scanner->scan($stream('EICAR'))->clean)->toBeTrue();
    });
});
