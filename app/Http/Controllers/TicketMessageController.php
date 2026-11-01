<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AddTicketMessage;
use App\Http\Requests\StoreTicketMessageRequest;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;

/**
 * Responder al cliente dentro de un ticket, con o sin adjuntos. El ticket se resuelve con el filtro de empresa (404 si
 * es de otra).
 */
class TicketMessageController extends Controller
{
    public function store(StoreTicketMessageRequest $request, Ticket $ticket, AddTicketMessage $add): RedirectResponse
    {
        $author = $request->user();
        abort_unless($author instanceof User, 403);

        $files = array_values(array_filter(Arr::wrap($request->file('files')), static fn (mixed $file): bool => $file instanceof UploadedFile));

        $add($author, $ticket, $request->string('body')->toString(), $files);

        return back();
    }
}
