<?php

declare(strict_types=1);

namespace App\Api;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;

/**
 * Lo que la API entrega de cada recurso: una lista cerrada de campos. Nada interno (el contexto de empresa, los
 * identificadores de hilos de correo o de sesiones, las notas internas) sale por aquí.
 */
final class Presenters
{
    /**
     * @return array<string, mixed>
     */
    public static function ticket(Ticket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'number' => $ticket->number,
            'subject' => $ticket->subject,
            'status' => $ticket->status->value,
            'priority' => $ticket->priority->value,
            'channel' => $ticket->channel->value,
            'customer_id' => $ticket->customer_id,
            'assigned_to' => $ticket->assigned_to,
            'created_at' => $ticket->created_at->toIso8601String(),
            'last_message_at' => $ticket->last_message_at->toIso8601String(),
            'resolved_at' => $ticket->resolved_at?->toIso8601String(),
            'closed_at' => $ticket->closed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function message(TicketMessage $message): array
    {
        return [
            'id' => $message->id,
            'ticket_id' => $message->ticket_id,
            'author' => $message->author->value,
            'body' => $message->body,
            'created_at' => $message->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function customer(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'created_at' => $customer->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function agent(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'role' => $user->role?->value];
    }
}
