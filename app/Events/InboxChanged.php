<?php

declare(strict_types=1);

namespace App\Events;

use App\Broadcasting\Channels;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Avisa a la bandeja de una empresa de que cambió algún ticket (uno nuevo, un mensaje, un estado, un agente). Solo
 * lleva identificadores; la lista y los contadores se vuelven a pedir por HTTP con los filtros de cada persona.
 */
final class InboxChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
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
        return [new PrivateChannel(Channels::inbox($this->tenantId))];
    }

    public function broadcastAs(): string
    {
        return 'inbox.changed';
    }

    /**
     * @return array{ticket: int}
     */
    public function broadcastWith(): array
    {
        return ['ticket' => $this->ticketId];
    }
}
