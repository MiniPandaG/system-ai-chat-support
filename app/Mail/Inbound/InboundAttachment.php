<?php

declare(strict_types=1);

namespace App\Mail\Inbound;

final class InboundAttachment
{
    public function __construct(
        public readonly string $name,
        public readonly string $content,
    ) {}
}
