<?php

declare(strict_types=1);

namespace App\Tickets;

use App\Models\Ticket;
use App\Models\TicketMessage;

/**
 * Anota cuándo el equipo (o la IA) respondió por primera vez al cliente (RF-37). Solo cuenta lo que el cliente recibe como
 * respuesta; el aviso fijo con el que la IA dice «te atenderá una persona» no lo es. Se anota una sola vez, dentro de la
 * transacción que guarda el mensaje y con el ticket ya bloqueado; quien llama es quien guarda.
 */
final class FirstResponse
{
    public static function stamp(Ticket $locked, TicketMessage $message): void
    {
        if ($locked->first_response_at === null && $message->author !== MessageAuthor::Customer) {
            $locked->forceFill(['first_response_at' => $message->created_at]);
        }
    }
}
