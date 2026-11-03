<?php

declare(strict_types=1);

namespace App\Mail\Inbound;

/**
 * De dónde salen los correos que entran. Hoy es un buzón IMAP; las pruebas usan un lector en memoria.
 */
interface MailboxReader
{
    /**
     * Los correos sin leer, hasta `$limit`, del más antiguo al más nuevo.
     *
     * @return list<InboundMail>
     */
    public function unread(int $limit): array;

    /** Marca el correo como leído para no volver a recibirlo. */
    public function markSeen(InboundMail $mail): void;
}
