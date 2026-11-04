<?php

declare(strict_types=1);

require_once __DIR__.'/../Tickets/Helpers.php';

use App\Attachments\AttachmentStatus;
use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\TicketAttachment;
use App\Privacy\TenantExport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TenantFootprint;

beforeEach(function (): void {
    useTemporaryAttachmentDisk();
    $this->exports = [];
});

afterEach(function (): void {
    foreach ($this->exports as $path) {
        File::delete($path);
    }
});

/**
 * Abre el ZIP con su contraseña y devuelve el texto de cada archivo.
 *
 * @return array<string, string>
 */
function exportContents(string $path, string $password): array
{
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $zip->setPassword($password);
    $files = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $content = $zip->getFromName($name);
        expect($content)->not->toBeFalse("No se pudo leer {$name} con la contraseña.");
        $files[$name] = (string) $content;
    }

    $zip->close();

    return $files;
}

describe('exportar una empresa', function (): void {
    it('entrega un ZIP cifrado con las filas de la empresa y sus archivos limpios', function (): void {
        $tenant = ticketTenant();
        $seed = TenantFootprint::seed($tenant);

        $result = app(TenantExport::class)->export($tenant);
        $this->exports[] = $result['path'];

        // Sin la contraseña no se lee nada.
        $locked = new ZipArchive;
        expect($locked->open($result['path']))->toBeTrue()
            ->and($locked->getFromName('datos/tickets.jsonl'))->toBeFalse();
        $locked->close();

        $files = exportContents($result['path'], $result['password']);

        expect($files)->toHaveKeys(['empresa.json', 'LEEME.txt', 'datos/tickets.jsonl', 'datos/ticket_messages.jsonl', 'datos/customers.jsonl', 'datos/kb_articles.jsonl', 'datos/users.jsonl'])
            ->and($files['datos/tickets.jsonl'])->toContain($seed['ticket']->subject)
            ->and(json_decode($files['empresa.json'], true))->toMatchArray(['id' => $tenant->id, 'name' => $tenant->name])
            ->and($result['rows']['tickets'])->toBe(1)
            ->and($result['files'])->toBe(2);

        $adjunto = collect($files)->first(fn (string $content, string $name): bool => str_starts_with($name, 'archivos/adjuntos/'));
        expect($adjunto)->toBe('contenido del adjunto')
            ->and(array_keys($files))->toContain("archivos/adjuntos/{$seed['attachment']->id}-factura_de_ejemplo.pdf");
    });

    it('no incluye contraseñas, huellas ni tokens, ni rutas internas', function (): void {
        $tenant = ticketTenant();
        $seed = TenantFootprint::seed($tenant);
        $seed['admin']->forceFill(['two_factor_secret' => encrypt('SECRETOPRUEBA'), 'two_factor_recovery_codes' => encrypt('["a"]')])->save();

        $result = app(TenantExport::class)->export($tenant);
        $this->exports[] = $result['path'];
        $everything = implode("\n", exportContents($result['path'], $result['password']));

        $secrets = [
            (string) DB::table('users')->where('id', $seed['admin']->id)->value('password'),
            (string) DB::table('users')->where('id', $seed['admin']->id)->value('two_factor_secret'),
            (string) DB::table('api_keys')->where('tenant_id', $tenant->id)->value('key_hash'),
            (string) DB::table('csat_responses')->where('tenant_id', $tenant->id)->value('token_hash'),
            (string) DB::table('invitations')->where('tenant_id', $tenant->id)->value('token_hash'),
            (string) DB::table('mail_threads')->where('tenant_id', $tenant->id)->value('token_hash'),
            (string) DB::table('widget_sessions')->where('tenant_id', $tenant->id)->value('token_hash'),
            $seed['attachmentPath'],
        ];

        foreach ($secrets as $secret) {
            expect($secret)->not->toBe('');
            expect($everything)->not->toContain($secret);
        }
    });

    it('nada de otra empresa entra en la exportación', function (): void {
        $mine = ticketTenant();
        $theirs = ticketTenant();
        TenantFootprint::seed($mine);
        $other = TenantFootprint::seed($theirs);
        $otherEmails = [$other['admin']->email, $other['agent']->email, 'nuevo@ejemplo.com'];

        $result = app(TenantExport::class)->export($mine);
        $this->exports[] = $result['path'];
        $everything = implode("\n", exportContents($result['path'], $result['password']));

        expect($everything)->not->toContain($other['ticket']->subject)
            ->and($everything)->not->toContain($other['attachment']->id)
            ->and($everything)->not->toContain($theirs->name);

        foreach ($otherEmails as $email) {
            if ($email !== 'nuevo@ejemplo.com') {
                expect($everything)->not->toContain($email);
            }
        }
    });

    it('un adjunto que no está limpio no sale', function (): void {
        $tenant = ticketTenant();
        $seed = TenantFootprint::seed($tenant);
        $path = $seed['attachmentPath'].'-malo';
        Storage::disk(config()->string('files.disk'))->put($path, 'amenaza');
        DB::table('ticket_attachments')->where('id', $seed['attachment']->id)->update(['status' => AttachmentStatus::Infected->value]);

        $result = app(TenantExport::class)->export($tenant);
        $this->exports[] = $result['path'];

        expect(array_keys(exportContents($result['path'], $result['password'])))
            ->not->toContain("archivos/adjuntos/{$seed['attachment']->id}-factura_de_ejemplo.pdf")
            ->and($result['files'])->toBe(1);
        expect(TicketAttachment::query()->withoutGlobalScopes()->find($seed['attachment']->id)?->status)->toBe(AttachmentStatus::Infected);
    });

    it('deja una auditoría con identificadores y no guarda la contraseña del ZIP', function (): void {
        $tenant = ticketTenant();
        TenantFootprint::seed($tenant);

        $result = app(TenantExport::class)->export($tenant);
        $this->exports[] = $result['path'];

        $entry = AuditLog::query()->where('event', AuditEvent::TenantExported->value)->firstOrFail();

        expect($entry->context)->toMatchArray(['tenant_id' => $tenant->id, 'via' => 'console'])
            ->and(json_encode($entry->context))->not->toContain($result['password'])
            ->and(is_dir(dirname($result['path'])))->toBeTrue()
            ->and(glob(dirname($result['path']).'/tmp*'))->toBe([]);
    });
});

describe('el comando tenant:export', function (): void {
    it('muestra dónde quedó el archivo y la contraseña una sola vez', function (): void {
        $tenant = ticketTenant();
        TenantFootprint::seed($tenant);

        $this->artisan('tenant:export', ['tenant' => $tenant->id])
            ->expectsOutputToContain('Archivo:')
            ->expectsOutputToContain('Contraseña del ZIP')
            ->assertSuccessful();

        foreach (glob(storage_path('app/private/exports/empresa-'.$tenant->id.'-*.zip')) ?: [] as $zip) {
            $this->exports[] = $zip;
        }

        expect($this->exports)->toHaveCount(1);
    });

    it('una empresa que no existe da un error claro', function (): void {
        $this->artisan('tenant:export', ['tenant' => 999999999])->assertFailed();
    });
});
