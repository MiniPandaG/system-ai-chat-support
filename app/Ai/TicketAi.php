<?php

declare(strict_types=1);

namespace App\Ai;

use App\Models\AiRun;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Tickets\MessageAuthor;

/**
 * Lo que la pantalla de un ticket muestra de la IA al equipo que puede responder: el borrador pendiente de revisión o, si
 * no hay, en qué quedó el último intento. Nunca incluye lo que escribió el cliente.
 */
class TicketAi
{
    /**
     * @return array{id: int, status: string, status_label: string, reason_label: string, body: string|null, sources: list<array{kind: string, title: string}>, created_label: string}|null
     */
    public function panel(Ticket $ticket): ?array
    {
        $run = AiRun::query()->where('ticket_id', $ticket->id)->orderByDesc('id')->first();

        if ($run === null) {
            return null;
        }

        $isDraft = $run->status === AiRunStatus::Drafted;
        // Un borrador deja de valer en cuanto alguien responde después del mensaje del cliente que lo originó.
        $live = $isDraft && $run->body !== null && ! $this->answeredSince($ticket, $run);
        $state = $isDraft && ! $live ? 'superseded' : $run->status->value;

        return [
            'id' => $run->id,
            'status' => $state,
            'status_label' => __('ai.status.'.$state),
            'reason_label' => __('ai.reasons.'.$run->reason->value),
            'body' => $live ? $run->body : null,
            'sources' => $live ? array_values(array_map(static fn (array $source): array => ['kind' => $source['kind'], 'title' => $source['title']], $run->sources ?? [])) : [],
            'created_label' => $run->created_at->isoFormat('D [de] MMM, h:mm a'),
        ];
    }

    private function answeredSince(Ticket $ticket, AiRun $run): bool
    {
        return TicketMessage::query()->where('ticket_id', $ticket->id)->where('id', '>', $run->trigger_message_id)->where('author', '!=', MessageAuthor::Customer->value)->exists();
    }
}
