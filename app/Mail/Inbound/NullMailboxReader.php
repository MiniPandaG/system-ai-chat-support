<?php

declare(strict_types=1);

namespace App\Mail\Inbound;

/** Sin buzón configurado no se lee nada: la aplicación sigue igual que sin correo entrante. */
final class NullMailboxReader implements MailboxReader
{
    public function unread(int $limit): array
    {
        return [];
    }

    public function markSeen(InboundMail $mail): void {}
}
