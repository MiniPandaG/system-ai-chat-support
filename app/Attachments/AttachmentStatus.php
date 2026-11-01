<?php

declare(strict_types=1);

namespace App\Attachments;

/**
 * Un adjunto solo se entrega cuando se revisó y salió limpio. Todo lo demás es «no».
 */
enum AttachmentStatus: string
{
    /** Subido y aún sin revisar. No se puede descargar. */
    case Pending = 'pending';

    /** Revisado sin hallazgos. Es el único estado descargable. */
    case Clean = 'clean';

    /** El antivirus encontró una amenaza: el archivo se borró y no se puede descargar. */
    case Infected = 'infected';

    /** No se pudo revisar (el antivirus no respondió tras varios intentos). Se trata igual que uno sin revisar. */
    case Failed = 'failed';
}
