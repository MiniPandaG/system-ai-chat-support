<?php

declare(strict_types=1);

namespace App\Events;

use App\Broadcasting\Channels;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Avisa al widget de un visitante de que su conversación cambió (llegó una respuesta, se cerró el ticket). No lleva
 * nada: el widget vuelve a pedir su conversación con su token, así que lo que ve sigue saliendo de la consulta que ya
 * excluye las notas internas.
 */
final class WidgetChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public int $tries = 3;

    public int $timeout = 10;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $sessionId,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(Channels::widget($this->tenantId, $this->sessionId))];
    }

    public function broadcastAs(): string
    {
        return 'widget.changed';
    }

    /**
     * @return array<string, never>
     */
    public function broadcastWith(): array
    {
        return [];
    }
}
