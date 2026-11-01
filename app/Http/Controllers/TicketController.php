<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateTicket;
use App\Actions\UpdateTicket;
use App\Enums\Role;
use App\Http\Requests\InboxRequest;
use App\Http\Requests\StoreTicketRequest;
use App\Http\Requests\UpdateTicketRequest;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\TicketView;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tickets\Inbox;
use App\Tickets\InboxFilters;
use App\Tickets\Thread;
use App\Tickets\TicketPriority;
use App\Tickets\TicketStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La bandeja y el ticket abierto. Todo se resuelve con el filtro automático de empresa, así que un ticket de otra
 * empresa da 404. El cliente nunca envía el número, la empresa ni el creador: los fija el servidor.
 */
class TicketController extends Controller
{
    public function __construct(private readonly Thread $thread, private readonly Inbox $inbox) {}

    public function index(InboxRequest $request): Response
    {
        return $this->render($request, null);
    }

    public function show(InboxRequest $request, Ticket $ticket): Response
    {
        Gate::authorize('view', $ticket);

        return $this->render($request, $ticket);
    }

    /** Los tramos siguientes de la lista para la carga progresiva. Mismos filtros que la pantalla; sin caché. */
    public function page(InboxRequest $request): JsonResponse
    {
        $user = $this->member($request);
        [$filters] = $this->resolve($request, $user);
        $cursor = $request->validated('cursor');
        $page = $this->inbox->page($filters, $user, is_string($cursor) ? $cursor : null);

        return response()->json(['data' => $page['rows'], 'next_cursor' => $page['next']])->header('Cache-Control', 'no-store');
    }

    public function store(StoreTicketRequest $request, CreateTicket $create, TenantContext $context): RedirectResponse
    {
        $creator = $request->user();
        $tenant = $context->current();
        abort_unless($creator instanceof User && $tenant !== null, 403);

        $ticket = $create($creator, $tenant, [
            'subject' => $request->string('subject')->toString(),
            'description' => $request->string('description')->toString(),
            'priority' => TicketPriority::from($request->string('priority')->toString()),
            'customer_id' => $request->filled('customer_id') ? $request->integer('customer_id') : null,
            'customer_name' => $request->filled('customer_name') ? $request->string('customer_name')->toString() : null,
            'customer_email' => $request->filled('customer_email') ? $request->string('customer_email')->toString() : null,
            'assigned_to' => $request->filled('assigned_to') ? $request->integer('assigned_to') : null,
        ]);

        return redirect()->route('tickets.show', $ticket)->with('status', __('tickets.created', ['number' => $ticket->number]));
    }

    public function update(UpdateTicketRequest $request, Ticket $ticket, UpdateTicket $update): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $validated = $request->validated();
        $changes = [];

        if (is_string($validated['status'] ?? null)) {
            $changes['status'] = TicketStatus::from($validated['status']);
        }

        if (is_string($validated['priority'] ?? null)) {
            $changes['priority'] = TicketPriority::from($validated['priority']);
        }

        if (array_key_exists('assigned_to', $validated)) {
            $changes['assigned_to'] = is_numeric($validated['assigned_to']) ? (int) $validated['assigned_to'] : null;
        }

        $update($actor, $ticket, $changes);

        return back()->with('status', __('tickets.updated'));
    }

    private function render(InboxRequest $request, ?Ticket $selected): Response
    {
        $user = $this->member($request);
        [$filters, $saved] = $this->resolve($request, $user);

        $views = TicketView::query()->visibleTo($user)->orderBy('name')->get();
        $counts = $this->inbox->counts($user, $views);
        $page = $this->inbox->page($filters, $user);

        return Inertia::render('tickets/Index', [
            'copy' => __('tickets'),
            'tickets' => $page['rows'],
            'next_cursor' => $page['next'],
            'total' => $this->inbox->filtered($filters, $user)->count(),
            'filters' => [...$filters->toArray(), 'saved' => $saved?->id],
            'counts' => ['all' => $counts['all'], 'mine' => $counts['mine'], 'unassigned' => $counts['unassigned']],
            'views' => array_values($views->map(fn (TicketView $view): array => [
                'id' => $view->id,
                'name' => $view->name,
                'shared' => $view->shared,
                'count' => $counts['views'][$view->id] ?? 0,
                'deletable' => Gate::allows('delete', $view),
            ])->all()),
            'filter_tags' => $this->tagOptions(),
            'selected' => $selected === null ? null : $this->detail($selected),
            'assignees' => $this->assignees($selected),
            'tag_options' => $selected !== null && Gate::allows('tag', $selected) ? $this->tagOptions() : [],
            'can' => [
                'create' => Gate::allows('create', Ticket::class),
                'update' => $selected !== null && Gate::allows('update', $selected),
                'save_view' => Gate::allows('create', TicketView::class),
            ],
        ]);
    }

    /**
     * Los filtros de la pantalla: los de la vista guardada si se pidió una (404 si no existe o es privada de otra
     * persona), con la búsqueda y el orden de la petición por encima.
     *
     * @return array{0: InboxFilters, 1: TicketView|null}
     */
    private function resolve(InboxRequest $request, User $user): array
    {
        $savedId = $request->savedId();

        if ($savedId === null) {
            return [$request->filters(), null];
        }

        $view = TicketView::query()->visibleTo($user)->find($savedId);
        abort_if($view === null, 404);

        $override = array_filter([
            'q' => $request->validated('q'),
            'sort' => $request->validated('sort'),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return [InboxFilters::fromArray([...$view->filters, ...$override]), $view];
    }

    private function member(InboxRequest $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * Las etiquetas de la empresa entre las que se puede elegir; con el tope por empresa la lista es corta.
     *
     * @return list<array{id: int, name: string, tone: string}>
     */
    private function tagOptions(): array
    {
        return array_values(Tag::query()->orderBy('name')->get()->map(fn (Tag $tag): array => ['id' => $tag->id, 'name' => $tag->name, 'tone' => $tag->tone->value])->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Ticket $ticket): array
    {
        $ticket->load(['customer', 'tags']);

        return [
            'id' => $ticket->id,
            'number' => $ticket->number,
            'subject' => $ticket->subject,
            'status' => $ticket->status->value,
            'priority' => $ticket->priority->value,
            'channel' => $ticket->channel->value,
            'assigned_to' => $ticket->assigned_to,
            'customer' => ['name' => $ticket->customer?->name, 'email' => $ticket->customer?->email],
            'created_at' => $ticket->created_at->toIso8601String(),
            'created_label' => $ticket->created_at->isoFormat('D [de] MMM [de] YYYY, h:mm a'),
            'tags' => $ticket->tags->sortBy('name')->map(fn (Tag $tag): array => ['id' => $tag->id, 'name' => $tag->name, 'tone' => $tag->tone->value])->values()->all(),
            'thread' => $this->thread->of($ticket, Gate::allows('viewNotes', $ticket)),
        ];
    }

    /**
     * Quienes pueden atender: administradores y agentes activos. Si el ticket sigue asignado a alguien que ya no
     * puede atender, se incluye para que el selector muestre el valor real en vez de uno vacío.
     *
     * @return list<array{id: int, name: string, active: bool}>
     */
    private function assignees(?Ticket $selected): array
    {
        $tenantId = app(TenantContext::class)->id();

        if ($tenantId === null) {
            return [];
        }

        $members = User::query()
            ->inTenant($tenantId)
            ->whereNull('disabled_at')
            ->whereIn('role', [Role::Admin->value, Role::Agent->value])
            ->orderBy('name')
            ->get(['id', 'name']);

        $options = array_values($members->map(fn (User $member): array => ['id' => $member->id, 'name' => $member->name, 'active' => true])->all());

        if ($selected?->assigned_to !== null && ! $members->contains('id', $selected->assigned_to)) {
            $previous = User::query()->inTenant($tenantId)->find($selected->assigned_to, ['id', 'name']);

            if ($previous !== null) {
                $options[] = ['id' => $previous->id, 'name' => $previous->name, 'active' => false];
            }
        }

        return $options;
    }
}
