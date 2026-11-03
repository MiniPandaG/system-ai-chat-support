<?php

declare(strict_types=1);

namespace App\Tickets;

use App\Attachments\AttachmentStore;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\Models\TicketNote;

/**
 * La conversación de un ticket tal como la ve el equipo: mensajes y notas internas mezclados por hora de creación.
 * Es el único lugar, junto con la acción que las crea, que lee notas; sin permiso para verlas, ni siquiera se consultan.
 */
class Thread
{
    public function __construct(private readonly AttachmentStore $attachments) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function of(Ticket $ticket, bool $withNotes): array
    {
        $ticket->loadMissing('customer');

        $entries = $ticket->messages()->with(['user:id,name', 'attachments'])->get()->map(fn (TicketMessage $message): array => [
            'sort' => $this->sortKey($message->created_at->format('U.u'), 0, $message->id),
            'entry' => [
                'kind' => 'message',
                'id' => $message->id,
                'author' => $message->author->value,
                'author_name' => $message->author === MessageAuthor::Customer ? $ticket->customer?->name : ($message->user === null ? __('tickets.integration') : $message->user->name),
                'body' => $message->body,
                'attachments' => array_values($message->attachments->map(fn (TicketAttachment $attachment): array => [
                    'id' => $attachment->id,
                    'name' => $attachment->original_name,
                    'size' => $attachment->size,
                    'status' => $attachment->status->value,
                    // Solo un adjunto revisado y limpio tiene enlace; los demás no se pueden descargar.
                    'url' => $this->attachments->downloadUrl($attachment),
                ])->all()),
                'created_at' => $message->created_at->toIso8601String(),
                'created_label' => $message->created_at->isoFormat('D [de] MMM, h:mm a'),
            ],
        ])->all();

        if ($withNotes) {
            $notes = $ticket->notes()->with('user:id,name')->get()->map(fn (TicketNote $note): array => [
                'sort' => $this->sortKey($note->created_at->format('U.u'), 1, $note->id),
                'entry' => [
                    'kind' => 'note',
                    'id' => $note->id,
                    'author_name' => $note->user?->name,
                    'body' => $note->body,
                    'created_at' => $note->created_at->toIso8601String(),
                    'created_label' => $note->created_at->isoFormat('D [de] MMM, h:mm a'),
                ],
            ])->all();

            $entries = [...$entries, ...$notes];
        }

        usort($entries, static fn (array $a, array $b): int => strcmp($a['sort'], $b['sort']));

        return array_values(array_map(static fn (array $item): array => $item['entry'], $entries));
    }

    /** Cadena que ordena bien con strcmp: segundos y microsegundos con ancho fijo, luego tipo e identificador. */
    private function sortKey(string $timestamp, int $kind, int $id): string
    {
        [$seconds, $micro] = array_pad(explode('.', $timestamp), 2, '0');

        return sprintf('%012d.%06d-%d-%020d', $seconds, $micro, $kind, $id);
    }
}
