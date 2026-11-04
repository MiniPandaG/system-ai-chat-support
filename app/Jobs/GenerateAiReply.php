<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\AiChannel;
use App\Ai\AiReason;
use App\Ai\AiRunStatus;
use App\Ai\ReplyFlow;
use App\Models\AiRun;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Tickets\MessageAuthor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Pone en marcha la respuesta de la IA a un mensaje del cliente (`ReplyFlow` decide todo lo demás). Trabaja con la empresa
 * que tenía el contexto al encolarlo y vuelve a comprobar al ejecutarse que el mensaje sigue siendo del cliente y del ticket.
 * Un solo intento: cada resultado, incluido un fallo, queda en la ejecución y nunca se manda dos veces la misma consulta al
 * modelo.
 */
class GenerateAiReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $ticketId, public readonly int $messageId, public readonly string $channel) {}

    public function handle(ReplyFlow $flow): void
    {
        $channel = AiChannel::tryFrom($this->channel);
        $ticket = Ticket::query()->find($this->ticketId);
        $message = TicketMessage::query()->find($this->messageId);

        if ($channel === null || $ticket === null || $message === null || $message->ticket_id !== $ticket->id || $message->author !== MessageAuthor::Customer) {
            return;
        }

        $flow->handle($ticket, $message, $channel);
    }

    public function failed(Throwable $exception): void
    {
        $run = AiRun::query()->where('trigger_message_id', $this->messageId)->first();

        if ($run !== null && $run->status === AiRunStatus::Pending) {
            $run->status = AiRunStatus::Failed;
            $run->reason = AiReason::ProviderError;
            $run->save();
        }
    }
}
