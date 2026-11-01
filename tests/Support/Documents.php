<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * Archivos de prueba para los adjuntos: documentos mínimos pero reales y archivos hostiles que fingen serlo.
 */
class Documents
{
    public static function pdf(string $text = 'Informe de prueba'): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n% {$text}\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    /**
     * @param  array<string, string>  $entries  Nombre de la entrada → contenido.
     */
    public static function zip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive;
        $zip->open((string) $path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();
        $bytes = (string) file_get_contents((string) $path);
        unlink((string) $path);

        return $bytes;
    }

    public static function docx(): string
    {
        return self::zip(['[Content_Types].xml' => '<Types/>', 'word/document.xml' => '<w:document/>']);
    }

    public static function xlsx(): string
    {
        return self::zip(['[Content_Types].xml' => '<Types/>', 'xl/workbook.xml' => '<workbook/>']);
    }

    public static function upload(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }
}
