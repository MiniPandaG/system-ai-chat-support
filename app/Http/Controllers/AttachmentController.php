<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TicketAttachment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Descarga de un adjunto. Se llega con una URL firmada que caduca y aun así hace falta sesión, ser de la empresa
 * dueña y poder ver el ticket; el adjunto se resuelve con el filtro de empresa, así que el de otra empresa da 404.
 * Solo se entrega uno revisado y limpio. Siempre como descarga, nunca dentro de la página: el navegador no lo
 * interpreta ni lo abre.
 */
class AttachmentController extends Controller
{
    public function download(TicketAttachment $attachment): StreamedResponse
    {
        $ticket = $attachment->ticket()->firstOrFail();
        Gate::authorize('view', $ticket);

        // Sin revisar, bloqueado o sin archivo: no existe para quien lo pide.
        abort_unless($attachment->isDownloadable(), 404);

        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists((string) $attachment->path), 404);

        // `download` fija «Content-Disposition: attachment» con el nombre codificado como manda el estándar.
        return $disk->download((string) $attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
            // Aunque algún navegador lo abriera, no podría ejecutar nada ni cargar nada.
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
