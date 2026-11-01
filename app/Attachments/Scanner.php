<?php

declare(strict_types=1);

namespace App\Attachments;

/**
 * Revisa el contenido de un archivo en busca de virus. Cualquier cosa que no sea una respuesta clara («limpio» o
 * «amenaza») debe lanzar `ScannerUnavailable`: nunca se da por limpio un archivo que no se pudo revisar.
 */
interface Scanner
{
    /**
     * @param  resource  $stream  El contenido, leído desde el principio.
     *
     * @throws ScannerUnavailable
     */
    public function scan($stream): ScanResult;
}
