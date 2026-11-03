<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreateTicket;
use App\Actions\UpdateTicket;
use App\Api\Presenters;
use App\Http\Controllers\Api\V1\Concerns\Paginates;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListRequest;
use App\Http\Requests\Api\StoreTicketRequest;
use App\Http\Requests\Api\UpdateTicketRequest;
use App\Models\Customer;
use App\Models\Ticket;
use App\Tenancy\TenantContext;
use App\Tickets\Channel;
use App\Tickets\TicketPriority;
use App\Tickets\TicketStatus;
use Illuminate\Http\JsonResponse;

/**
 * Tickets por la API. Todo se lee y se escribe dentro de la empresa de la llave: un ticket de otra empresa da 404.
 */
class TicketController extends Controller
{
    use Paginates;

    public function index(ListRequest $request): JsonResponse
    {
        $size = $request->pageSize();
        $cursor = $request->cursor();
        $assigned = $request->filled('assigned_to') ? $request->string('assigned_to')->toString() : null;

        $tickets = Ticket::query()
            ->when($cursor !== null, fn ($query) => $query->where('id', '<', $cursor))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('priority'), fn ($query) => $query->where('priority', $request->string('priority')->toString()))
            ->when($request->filled('customer_id'), fn ($query) => $query->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('number'), fn ($query) => $query->where('number', $request->integer('number')))
            ->when($assigned === 'none', fn ($query) => $query->whereNull('assigned_to'))
            ->when(is_string($assigned) && $assigned !== 'none', fn ($query) => $query->where('assigned_to', (int) $assigned))
            ->orderByDesc('id')
            ->limit($size + 1)
            ->get();

        return $this->page(array_values($tickets->all()), $size, Presenters::ticket(...));
    }

    public function store(StoreTicketRequest $request, CreateTicket $create, TenantContext $context): JsonResponse
    {
        $tenant = $context->current();
        abort_if($tenant === null, 401);

        $customerId = $request->filled('customer_id') ? $request->integer('customer_id') : null;
        $email = $request->filled('customer_email') ? $request->string('customer_email')->toString() : null;

        // Un correo que ya es de un cliente de la empresa es ese cliente: no se duplica ni se rechaza.
        if ($customerId === null && $email !== null) {
            $customerId = Customer::query()->where('email', $email)->value('id');
            $customerId = is_int($customerId) ? $customerId : null;
        }

        $ticket = $create(null, $tenant, [
            'subject' => $request->string('subject')->toString(),
            'description' => $request->string('description')->toString(),
            'priority' => TicketPriority::tryFrom($request->string('priority')->toString()) ?? TicketPriority::Normal,
            'customer_id' => $customerId,
            'customer_name' => $request->filled('customer_name') ? $request->string('customer_name')->toString() : null,
            'customer_email' => $email,
            'assigned_to' => $request->filled('assigned_to') ? $request->integer('assigned_to') : null,
        ], Channel::Api);

        return response()->json(['data' => Presenters::ticket($ticket)], 201, ['Location' => url('/api/v1/tickets/'.$ticket->id)]);
    }

    public function show(Ticket $ticket): JsonResponse
    {
        return response()->json(['data' => Presenters::ticket($ticket)]);
    }

    public function update(UpdateTicketRequest $request, Ticket $ticket, UpdateTicket $update): JsonResponse
    {
        $changes = [];

        if ($request->has('status')) {
            $changes['status'] = TicketStatus::from($request->string('status')->toString());
        }

        if ($request->has('priority')) {
            $changes['priority'] = TicketPriority::from($request->string('priority')->toString());
        }

        if ($request->has('assigned_to')) {
            $changes['assigned_to'] = $request->filled('assigned_to') ? $request->integer('assigned_to') : null;
        }

        $update(null, $ticket, $changes);

        return response()->json(['data' => Presenters::ticket($ticket->refresh())]);
    }
}
