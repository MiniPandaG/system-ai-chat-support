<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\AddApiMessage;
use App\Api\Presenters;
use App\Http\Controllers\Api\V1\Concerns\Paginates;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListRequest;
use App\Http\Requests\Api\StoreMessageRequest;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Tickets\MessageAuthor;
use Illuminate\Http\JsonResponse;

/**
 * Los mensajes de un ticket. Salen de la tabla de mensajes y nunca tocan las notas internas, que son solo del equipo.
 */
class MessageController extends Controller
{
    use Paginates;

    public function index(ListRequest $request, Ticket $ticket): JsonResponse
    {
        $size = $request->pageSize();
        $cursor = $request->cursor();

        $messages = TicketMessage::query()
            ->where('ticket_id', $ticket->id)
            ->when($cursor !== null, fn ($query) => $query->where('id', '>', $cursor))
            ->orderBy('id')
            ->limit($size + 1)
            ->get();

        return $this->page(array_values($messages->all()), $size, Presenters::message(...));
    }

    public function store(StoreMessageRequest $request, Ticket $ticket, AddApiMessage $add): JsonResponse
    {
        $author = $request->input('author') === 'agent' ? MessageAuthor::Agent : MessageAuthor::Customer;

        $message = $add($ticket, $author, $request->string('body')->toString());

        return response()->json(['data' => Presenters::message($message)], 201);
    }
}
