<?php

declare(strict_types=1);

namespace App\Mail\Inbound;

use Closure;

/**
 * Un correo que llegó al buzón, tal como lo entrega el lector: las cabeceras y el peso se conocen de entrada y el
 * contenido (texto y adjuntos) solo se descarga cuando las comprobaciones baratas ya lo aceptaron. Todo es contenido no
 * confiable: nada de esto se usa sin pasar por `InboundMailProcessor`.
 */
final class InboundMail
{
    /**
     * @param  array<string, string>  $headers  Solo las cabeceras que importan, con el nombre en minúsculas.
     * @param  Closure(): InboundBody  $body  Descarga el contenido.
     */
    public function __construct(
        public readonly object $handle,
        public readonly string $messageId,
        public readonly ?string $from,
        public readonly string $recipients,
        public readonly string $references,
        public readonly array $headers,
        public readonly int $size,
        public readonly Closure $body,
    ) {}

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }
}
