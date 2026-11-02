<?php

declare(strict_types=1);

namespace App\Widget;

use App\Models\TicketMessage;
use App\Models\WidgetSession;

/**
 * El resultado de enviar un mensaje desde el widget. El token solo viene cuando la sesión nació con este mensaje:
 * es la única vez que sale del servidor.
 */
final class Sent
{
    public function __construct(
        public readonly WidgetSession $session,
        public readonly TicketMessage $message,
        public readonly ?string $token,
    ) {}
}
