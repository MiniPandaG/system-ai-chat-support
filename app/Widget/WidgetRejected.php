<?php

declare(strict_types=1);

namespace App\Widget;

use RuntimeException;

/**
 * El widget no puede atender lo que se pidió. El mensaje es el que ve el visitante: nunca nombra el plan, los límites ni
 * nada de la empresa que no deba saber quien escribe desde fuera.
 */
final class WidgetRejected extends RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function unavailable(): self
    {
        return new self(__('widget.errors.unavailable'), 503);
    }

    public static function tooManyConversations(): self
    {
        return new self(__('widget.errors.too_many_conversations'), 429);
    }
}
