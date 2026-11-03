<?php

declare(strict_types=1);

namespace App\Events;

use App\Broadcasting\Channels;
use App\Knowledge\SourceKind;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Avisa a quien tiene abierta la base de conocimiento de una empresa de que algo cambió (un documento terminó de revisarse
 * o de leerse, algo terminó de indexarse). Solo lleva el tipo y el identificador; la pantalla vuelve a pedir lo que muestra
 * por HTTP.
 */
final class KnowledgeChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public int $tries = 3;

    public int $timeout = 10;

    public function __construct(
        public readonly int $tenantId,
        public readonly SourceKind $kind,
        public readonly int $id,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(Channels::knowledge($this->tenantId))];
    }

    public function broadcastAs(): string
    {
        return 'knowledge.changed';
    }

    /**
     * @return array{kind: string, id: int}
     */
    public function broadcastWith(): array
    {
        return ['kind' => $this->kind->value, 'id' => $this->id];
    }
}
