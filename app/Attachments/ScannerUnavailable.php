<?php

declare(strict_types=1);

namespace App\Attachments;

use RuntimeException;

/**
 * El antivirus no pudo dar una respuesta (no contesta, se cortó la conexión o devolvió un error). No es lo mismo que
 * «limpio»: el archivo se queda sin entregar y la revisión se reintenta.
 */
class ScannerUnavailable extends RuntimeException {}
