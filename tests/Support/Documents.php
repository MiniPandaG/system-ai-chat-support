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
     * Un PDF válido con una página por cada texto (solo ASCII), para probar la lectura del texto de verdad.
     *
     * @param  list<string>  $pages
     * @param  bool  $compress  Con el contenido de cada página comprimido (FlateDecode), como lo guardan casi todos los programas.
     */
    public static function textPdf(array $pages, bool $compress = false): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids ['.implode(' ', array_map(fn (int $i): string => (4 + 2 * $i).' 0 R', array_keys($pages))).'] /Count '.count($pages).' >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        foreach (array_values($pages) as $index => $text) {
            $stream = 'BT /F1 12 Tf 72 720 Td ('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text).') Tj ET';
            $objects[4 + 2 * $index] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents '.(5 + 2 * $index).' 0 R /Resources << /Font << /F1 3 0 R >> >> >>';
            $filter = '';

            if ($compress) {
                $stream = (string) gzcompress($stream);
                $filter = ' /Filter /FlateDecode';
            }

            $objects[5 + 2 * $index] = '<< /Length '.strlen($stream).$filter." >>\nstream\n{$stream}\nendstream";
        }

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
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
