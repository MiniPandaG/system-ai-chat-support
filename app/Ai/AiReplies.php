<?php

declare(strict_types=1);

namespace App\Ai;

use App\Jobs\GenerateAiReply;
use App\Models\AiSettings;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Tickets\MessageAuthor;

/**
 * El único punto por el que un mensaje del cliente puede poner a trabajar a la IA. Solo encola si la empresa la tiene
 * encendida en ese canal; el trabajo vuelve a comprobarlo todo al ejecutarse. Debe haber una empresa en contexto.
 */
class AiReplies
{
    public function afterCustomerMessage(Ticket $ticket, TicketMessage $message, AiChannel $channel): void
    {
        if ($message->author !== MessageAuthor::Customer || $message->ticket_id !== $ticket->id) {
            return;
        }

        if (AiSettings::current()->modeFor($channel) === AiMode::Off) {
            return;
        }

        GenerateAiReply::dispatch($ticket->id, $message->id, $channel->value)
            ->delay(now()->addSeconds(config()->integer('ai.reply.debounce_seconds')))
            ->afterCommit();
    }
}
