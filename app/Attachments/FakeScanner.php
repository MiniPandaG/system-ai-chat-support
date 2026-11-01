<?php

declare(strict_types=1);

namespace App\Attachments;

/**
 * Antivirus de mentira para pruebas e integración continua, donde no hay un demonio de ClamAV. Marca como amenaza
 * solo un archivo que sea la cadena de prueba EICAR, igual que haría el real (comprobado contra ClamAV), para que los
 * mismos casos se ejerciten sin él. El proveedor de servicios se niega a usarlo en producción.
 */
final class FakeScanner implements Scanner
{
    /** La cadena estándar de prueba de antivirus, partida para que ningún antivirus de escritorio marque este archivo. */
    public const EICAR_SIGNATURE = 'Eicar-Test-Signature';

    public static function eicar(): string
    {
        return 'X5O!P%@AP[4\PZX54(P^)7CC)7}$'.'EICAR-STANDARD-ANTIVIRUS-TEST-FILE!'.'$H+H*';
    }

    public function scan($stream): ScanResult
    {
        $content = stream_get_contents($stream);

        if ($content === false) {
            throw new ScannerUnavailable('No se pudo leer el archivo que había que revisar.');
        }

        // Como el ClamAV de verdad: la firma de EICAR es el archivo entero (con espacios o saltos de línea al final).
        return rtrim($content, "\r\n\t ") === self::eicar() ? ScanResult::infected(self::EICAR_SIGNATURE) : ScanResult::clean();
    }
}
