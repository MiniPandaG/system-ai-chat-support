<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Http\UploadedFile;

/**
 * Archivos de prueba: imágenes reales hechas con GD y archivos hostiles que fingen serlo.
 */
class Images
{
    public static function png(int $width = 128, int $height = 128): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 246, 95, 30));

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public static function jpeg(int $width = 128, int $height = 128): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 95, 246));

        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    public static function webp(int $width = 128, int $height = 128): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 246, 95));

        ob_start();
        imagewebp($image);

        return (string) ob_get_clean();
    }

    public static function gif(int $width = 128, int $height = 128): string
    {
        $image = imagecreatetruecolor($width, $height);

        ob_start();
        imagegif($image);

        return (string) ob_get_clean();
    }

    public static function svg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128"><script>alert(1)</script><rect width="128" height="128"/></svg>';
    }

    /** Un PNG válido con código escondido después de su fin. */
    public static function pngWithPayload(): string
    {
        return self::png().'<?php system($_GET["c"]); ?>';
    }

    /** Un PNG con una cabecera que declara medidas enormes y casi ningún dato: una bomba de descompresión. */
    public static function pngClaiming(int $width, int $height): string
    {
        $ihdr = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n".self::chunk('IHDR', $ihdr).self::chunk('IDAT', "\x00").self::chunk('IEND', '');
    }

    /** Un PNG animado (APNG): lleva un fragmento acTL antes de los datos. */
    public static function animatedPng(): string
    {
        $png = self::png();
        $afterIhdr = 8 + 4 + 4 + 13 + 4;

        return substr($png, 0, $afterIhdr).self::chunk('acTL', pack('NN', 2, 0)).substr($png, $afterIhdr);
    }

    public static function upload(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }
}
