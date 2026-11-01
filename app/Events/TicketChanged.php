<?php

declare(strict_types=1);

namespace App\Events;

use App\Broadcasting\Channels;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Avisa a quien tiene un ticket abierto de que algo cambió en él. No lleva texto, nombres ni notas: solo los
 * identificadores. Cada navegador vuelve a pedir el ticket por HTTP, así que lo que ve sigue pasando por la política y
 * el filtro de empresa de siempre (un observador sigue sin recibir las notas aunque le llegue este aviso).
 */
final class TicketChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public int $tries = 3;

    public int $timeout = 10;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $ticketId,
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
        return 'ticket.changed';
    }

    /**
     * @return array{ticket: int}
     */
    public function broadcastWith(): array
    {
        return ['ticket' => $this->ticketId];
    }
}
