<?php

declare(strict_types=1);

namespace App\Events;

use App\Broadcasting\Channels;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Una persona del equipo está escribiendo en el ticket. Es una señal que caduca sola en el navegador: no se guarda,
 * no se reintenta y solo lleva identificadores (el navegador resuelve el nombre con la lista del equipo que ya tiene).
 * La emite el servidor, después de comprobar el permiso y el límite; el navegador nunca publica en el canal.
 */
final class TicketTyping implements ShouldBroadcast
{
    use Dispatchable;

    public int $tries = 1;

    public int $timeout = 10;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $ticketId,
        public readonly int $userId,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(Channels::ticket($this->tenantId, $this->ticketId))];
    }

    public function broadcastAs(): string
    {
        return 'ticket.typing';
    }

    /**
     * @return array{user: int}
     */
    public function broadcastWith(): array
    {
        return ['user' => $this->userId];
    }
}
