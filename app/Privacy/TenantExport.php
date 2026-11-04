<?php

declare(strict_types=1);

namespace App\Privacy;

use App\Enums\AuditEvent;
use App\Models\Tenant;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Exporta todo lo de una empresa en un ZIP cifrado con AES-256 (RS-17): una línea de JSON por fila y tabla, los archivos
 * limpios y un LEEME. Se deja fuera lo secreto (contraseñas, huellas de llaves y tokens, códigos de 2FA) y las rutas internas.
 * La contraseña del ZIP se genera aquí y se entrega una sola vez a quien lo pidió; no se guarda en ninguna parte.
 *
 * Solo se llama desde la consola. Cada fila sale con filtro explícito de empresa: lo de otra empresa no puede entrar.
 */
class TenantExport
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{path: string, password: string, rows: array<string, int>, files: int}
     */
    public function export(Tenant $tenant): array
    {
        $directory = storage_path('app/private/exports');

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('No se pudo crear la carpeta de exportaciones.');
        }

        $stamp = now()->format('Ymd-His');
        $path = "{$directory}/empresa-{$tenant->id}-{$stamp}-".Str::lower(Str::random(6)).'.zip';
        $password = Str::random(32);
        $temporary = [];
        /** @var array<string, int> $rows */
        $rows = [];
        $files = 0;

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('No se pudo crear el archivo de exportación.');
        }

        try {
            $this->add($zip, 'empresa.json', $this->writeText($temporary, json_encode($this->companyRow($tenant), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)), $password);
            $this->add($zip, 'LEEME.txt', $this->writeText($temporary, $this->readme($tenant)), $password);

            foreach (TenantTables::EXPORT as $table => $except) {
                $rows[$table] = 0;
                $file = $this->temporaryFile($temporary);
                $handle = fopen($file, 'wb');

                if ($handle === false) {
                    throw new RuntimeException('No se pudo escribir un archivo temporal.');
                }

                foreach (DB::table($table)->where('tenant_id', $tenant->id)->cursor() as $row) {
                    fwrite($handle, json_encode(array_diff_key((array) $row, array_flip($except)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
                    $rows[$table]++;
                }

                fclose($handle);
                $this->add($zip, "datos/{$table}.jsonl", $file, $password);
            }

            $files = $this->addFiles($zip, $tenant, $temporary, $password);

            $zip->close();
        } catch (Throwable $failure) {
            if (is_file($path)) {
                unlink($path);
            }

            throw $failure;
        } finally {
            foreach ($temporary as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        chmod($path, 0600);

        $this->audit->record(AuditEvent::TenantExported, null, [
            'tenant_id' => $tenant->id,
            'rows' => array_sum($rows),
            'files' => $files,
            'via' => 'console',
        ]);

        return ['path' => $path, 'password' => $password, 'rows' => $rows, 'files' => $files];
    }

    /**
     * Los adjuntos limpios, el logo y los documentos de conocimiento que sigan en disco. Lo que no esté limpio no sale.
     *
     * @param  list<string>  $temporary
     */
    private function addFiles(ZipArchive $zip, Tenant $tenant, array &$temporary, string $password): int
    {
        $count = 0;

        $attachments = DB::table('ticket_attachments')->where('tenant_id', $tenant->id)->where('status', 'clean')->orderBy('id')->cursor();

        foreach ($attachments as $row) {
            $attachment = (array) $row;
            $name = 'archivos/adjuntos/'.$this->text($attachment, 'id').'-'.$this->safeName($this->text($attachment, 'original_name'));
            $count += $this->addFromDisk($zip, $this->text($attachment, 'disk'), $this->text($attachment, 'path'), $name, $temporary, $password);
        }

        foreach (DB::table('files')->where('tenant_id', $tenant->id)->orderBy('id')->cursor() as $row) {
            $file = (array) $row;
            $count += $this->addFromDisk($zip, $this->text($file, 'disk'), $this->text($file, 'path'), 'archivos/logos/'.$this->text($file, 'id').'.png', $temporary, $password);
        }

        return $count;
    }

    /**
     * @param  list<string>  $temporary
     */
    private function addFromDisk(ZipArchive $zip, string $disk, string $path, string $entry, array &$temporary, string $password): int
    {
        $storage = Storage::disk($disk);

        if (! $storage->exists($path)) {
            return 0;
        }

        $source = $storage->readStream($path);
        $file = $this->temporaryFile($temporary);
        $target = fopen($file, 'wb');

        if ($source === null || $target === false) {
            return 0;
        }

        stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);

        $this->add($zip, $entry, $file, $password);

        return 1;
    }

    private function add(ZipArchive $zip, string $entry, string $file, string $password): void
    {
        if (! $zip->addFile($file, $entry) || ! $zip->setEncryptionName($entry, ZipArchive::EM_AES_256, $password)) {
            throw new RuntimeException('No se pudo añadir un archivo a la exportación.');
        }
    }

    /**
     * Escribe un texto en un archivo temporal que se borra al terminar.
     *
     * @param  list<string>  $temporary
     */
    private function writeText(array &$temporary, string $content): string
    {
        $file = $this->temporaryFile($temporary);

        if (file_put_contents($file, $content) === false) {
            throw new RuntimeException('No se pudo escribir un archivo temporal.');
        }

        return $file;
    }

    /**
     * @param  list<string>  $temporary
     */
    private function temporaryFile(array &$temporary): string
    {
        $file = tempnam(storage_path('app/private/exports'), 'tmp');

        if ($file === false) {
            throw new RuntimeException('No se pudo crear un archivo temporal.');
        }

        $temporary[] = $file;

        return $file;
    }

    /**
     * @return array<string, mixed>
     */
    private function companyRow(Tenant $tenant): array
    {
        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'plan' => $tenant->plan?->slug,
            'created_at' => $tenant->created_at?->toIso8601String(),
            'exported_at' => now()->toIso8601String(),
        ];
    }

    private function readme(Tenant $tenant): string
    {
        return "Exportación de los datos de «{$tenant->name}» (empresa {$tenant->id}), generada el ".now()->toIso8601String().".\n\n"
            ."- datos/: una tabla por archivo, una fila de JSON por línea.\n"
            ."- archivos/: los adjuntos revisados y limpios y los logos.\n"
            ."- No incluye contraseñas, códigos de verificación en dos pasos, llaves, tokens ni rutas internas.\n\n"
            ."Contiene datos personales: entrégalo solo a la persona autorizada, por un canal seguro, y bórralo del servidor cuando ya no haga falta.\n";
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private function text(array $row, string $column): string
    {
        $value = $row[$column] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    private function safeName(string $name): string
    {
        $clean = preg_replace('/[^\p{L}\p{N}._-]+/u', '_', basename($name));

        return mb_substr(is_string($clean) && $clean !== '' ? $clean : 'archivo', 0, 100);
    }
}
