<?php

declare(strict_types=1);

namespace App\Mail\Inbound;

/**
 * El contenido de un correo entrante. Los adjuntos traen su nombre y su tipo según el remitente (que no vale nada) y el
 * contenido en bytes; `AttachmentStore` decide con el contenido en la mano.
 */
final class InboundBody
{
    /**
     * @param  list<InboundAttachment>  $attachments
     */
    public function __construct(
        public readonly string $text,
        public readonly string $html,
        public readonly array $attachments = [],
    ) {}
}
