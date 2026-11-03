<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Mail\Inbound\InboundMail;
use App\Mail\Inbound\MailboxReader;

/** Un buzón en memoria: entrega lo que se le dé y recuerda qué correos se marcaron como leídos. */
class FakeMailboxReader implements MailboxReader
{
    /** @var list<string> */
    public array $seen = [];

    /**
     * @param  list<InboundMail>  $mails
     */
    public function __construct(public array $mails = []) {}

    public function unread(int $limit): array
    {
        return array_slice(array_values(array_filter($this->mails, fn (InboundMail $mail): bool => ! in_array($mail->messageId, $this->seen, true))), 0, $limit);
    }

    public function markSeen(InboundMail $mail): void
    {
        $this->seen[] = $mail->messageId;
    }
}
