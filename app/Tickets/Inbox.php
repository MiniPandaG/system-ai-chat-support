<?php

declare(strict_types=1);

namespace App\Tickets;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketView;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Todo lo que la bandeja pregunta a la base de datos: filtrar, buscar, ordenar y contar. Las consultas parten de
 * `Ticket::query()`, así que el filtro automático de empresa siempre se aplica; nada de aquí construye SQL a mano.
 */
class Inbox
{
    /** Tickets por tramo de la carga progresiva. */
    public const PAGE_SIZE = 30;

    /** Palabras de la búsqueda que se usan; el resto se descarta para no encarecer la consulta. */
    private const MAX_WORDS = 8;

    /** Lo que sigue pendiente: los filtros «Asignados a mí» y «Sin asignar» muestran solo esto si no se elige estado. */
    private const PENDING = [TicketStatus::Open, TicketStatus::WaitingCustomer];

    public function __construct(private readonly Reads $reads) {}

    /**
     * Los tickets que cumplen los filtros, sin orden ni relaciones.
     *
     * @return Builder<Ticket>
     */
    public function filtered(InboxFilters $filters, User $user): Builder
    {
        $query = Ticket::query();
        $statuses = $filters->statuses === [] && $filters->scope !== InboxScope::All ? self::PENDING : $filters->statuses;

        match ($filters->scope) {
            InboxScope::Mine => $query->where('assigned_to', $user->id),
            InboxScope::Unassigned => $query->whereNull('assigned_to'),
            InboxScope::All => null,
        };

        if ($statuses !== []) {
            $query->whereIn('status', array_map(static fn (TicketStatus $status): string => $status->value, $statuses));
        }

        if ($filters->priorities !== []) {
            $query->whereIn('priority', array_map(static fn (TicketPriority $priority): string => $priority->value, $filters->priorities));
        }

        if ($filters->agent !== null) {
            $query->where('assigned_to', $filters->agent);
        }

        if ($filters->tag !== null) {
            $tagId = $filters->tag;
            $query->whereHas('tags', fn (Builder $tags) => $tags->where('tags.id', $tagId));
        }

        if ($filters->from !== null) {
            $query->where('created_at', '>=', Carbon::parse($filters->from)->startOfDay());
        }

        if ($filters->to !== null) {
            $query->where('created_at', '<=', Carbon::parse($filters->to)->endOfDay());
        }

        if ($filters->search !== null) {
            $this->search($query, $filters->search);
        }

        return $query;
    }

    /**
     * Un tramo de la lista, con el orden pedido, y el cursor del siguiente. La carga progresiva usa un cursor: no
     * recorre ni cuenta lo anterior.
     *
     * @return array{rows: list<array<string, mixed>>, next: string|null}
     */
    public function page(InboxFilters $filters, User $user, ?string $cursor = null): array
    {
        $query = $this->filtered($filters, $user)->with(['customer:id,name', 'assignee:id,name']);

        match ($filters->sort) {
            InboxSort::Recent => $query->orderByDesc('last_message_at')->orderByDesc('id'),
            InboxSort::Oldest => $query->orderBy('created_at')->orderBy('id'),
            InboxSort::Priority => $query->orderByDesc('priority_rank')->orderByDesc('last_message_at')->orderByDesc('id'),
        };

        // El cursor llega del navegador, así que se comprueba que sea de este orden antes de usarlo; uno inventado o de otro
        // orden vuelve al primer tramo en vez de romper la consulta. El nombre «after» evita que Laravel lo lea por su cuenta.
        $page = $query->cursorPaginate(self::PAGE_SIZE, ['*'], 'after', $this->cursor($cursor, $filters->sort));

        return [
            'rows' => $this->rows($page->items(), $filters->search, $user),
            'next' => $page instanceof CursorPaginator ? $page->nextCursor()?->encode() : null,
        ];
    }

    private function cursor(?string $encoded, InboxSort $sort): ?Cursor
    {
        if ($encoded === null) {
            return null;
        }

        $cursor = Cursor::fromEncoded($encoded);
        $expected = match ($sort) {
            InboxSort::Recent => ['last_message_at', 'id'],
            InboxSort::Oldest => ['created_at', 'id'],
            InboxSort::Priority => ['priority_rank', 'last_message_at', 'id'],
        };

        if ($cursor === null) {
            return null;
        }

        $given = array_keys(array_diff_key($cursor->toArray(), ['_pointsToNextItems' => true]));
        sort($given);
        sort($expected);

        return $given === $expected ? $cursor : null;
    }

    /**
     * Las filas que ve la lista. Con una búsqueda, cada una dice en qué coincidió; y cada una dice si está sin leer para
     * quien la mira.
     *
     * @param  iterable<int, Ticket>  $tickets
     * @return list<array<string, mixed>>
     */
    public function rows(iterable $tickets, ?string $search, User $viewer): array
    {
        $tickets = Collection::make($tickets);
        $matches = $search === null ? [] : $this->matchSources($tickets, $search);
        $unread = array_flip($this->reads->unreadIds($viewer, array_values($tickets->map(static fn (Ticket $ticket): int => $ticket->id)->all())));

        return array_values($tickets->map(fn (Ticket $ticket): array => [
            'id' => $ticket->id,
            'number' => $ticket->number,
            'subject' => $ticket->subject,
            'status' => $ticket->status->value,
            'priority' => $ticket->priority->value,
            'customer_name' => $ticket->customer?->name,
            'assignee_name' => $ticket->assignee?->name,
            'last_message_at' => $ticket->last_message_at->toIso8601String(),
            'last_message_label' => $ticket->last_message_at->isoFormat('D [de] MMM, h:mm a'),
            'match' => $matches[$ticket->id] ?? null,
            'unread' => isset($unread[$ticket->id]),
        ])->all());
    }

    /**
     * Cuántos tickets tiene cada filtro rápido y cada vista que la persona puede ver.
     *
     * @param  Collection<int, TicketView>  $views
     * @return array{all: int, mine: int, unassigned: int, views: array<int, int>}
     */
    public function counts(User $user, Collection $views): array
    {
        $counts = [
            'all' => $this->filtered(new InboxFilters, $user)->count(),
            'mine' => $this->filtered(new InboxFilters(scope: InboxScope::Mine), $user)->count(),
            'unassigned' => $this->unassigned($user),
            'views' => [],
        ];

        foreach ($views as $view) {
            $counts['views'][$view->id] = $this->filtered($view->inboxFilters(), $user)->count();
        }

        return $counts;
    }

    /** Los tickets pendientes que nadie atiende: el número que acompaña a «Bandeja» en la barra lateral. */
    public function unassigned(User $user): int
    {
        return $this->filtered(new InboxFilters(scope: InboxScope::Unassigned), $user)->count();
    }

    /**
     * @param  Builder<Ticket>  $query
     */
    private function search(Builder $query, string $text): void
    {
        $words = $this->words($text);
        $number = preg_match('/\A#?(\d{1,9})\z/', $text, $found) === 1 ? (int) $found[1] : null;

        $query->where(function (Builder $any) use ($words, $number): void {
            if ($words !== []) {
                $terms = $this->booleanTerms($words);

                $any->whereFullText('subject', $terms, ['mode' => 'boolean'])
                    ->orWhereHas('messages', fn (Builder $messages) => $messages->whereFullText('body', $terms, ['mode' => 'boolean']))
                    ->orWhereHas('customer', fn (Builder $customers) => $customers->whereFullText(['name', 'email'], $terms, ['mode' => 'boolean']));
            }

            if ($number !== null) {
                $any->orWhere('number', $number);
            }

            if ($words === [] && $number === null) {
                // Un texto sin palabras utilizables no coincide con nada.
                $any->whereIn('tickets.id', []);
            }
        });
    }

    /**
     * En qué campo coincidió cada ticket de la página: número, asunto, cliente o mensaje.
     *
     * @param  Collection<int, Ticket>  $tickets
     * @return array<int, string>
     */
    private function matchSources(Collection $tickets, string $text): array
    {
        $words = $this->words($text);
        $ids = $tickets->pluck('id')->all();
        $terms = $words === [] ? null : $this->booleanTerms($words);
        $number = preg_match('/\A#?(\d{1,9})\z/', $text, $found) === 1 ? (int) $found[1] : null;

        $inSubject = $terms === null ? [] : Ticket::query()->whereIn('id', $ids)->whereFullText('subject', $terms, ['mode' => 'boolean'])->pluck('id')->all();
        $inMessage = $terms === null ? [] : TicketMessage::query()->whereIn('ticket_id', $ids)->whereFullText('body', $terms, ['mode' => 'boolean'])->distinct()->pluck('ticket_id')->all();
        $customerIds = $terms === null ? [] : Customer::query()->whereIn('id', $tickets->pluck('customer_id')->all())->whereFullText(['name', 'email'], $terms, ['mode' => 'boolean'])->pluck('id')->all();

        $sources = [];

        foreach ($tickets as $ticket) {
            $sources[$ticket->id] = match (true) {
                $number !== null && $ticket->number === $number => 'number',
                in_array($ticket->id, $inSubject, true) => 'subject',
                in_array($ticket->customer_id, $customerIds, true) => 'customer',
                in_array($ticket->id, $inMessage, true) => 'message',
                default => null,
            };
        }

        return array_filter($sources, static fn (?string $source): bool => $source !== null);
    }

    /**
     * Las palabras de la búsqueda: solo letras y números, de dos caracteres o más. Así ningún operador del modo
     * booleano de MySQL (+ - * " ( ) < > ~ @) llega a la consulta.
     *
     * @return list<string>
     */
    private function words(string $text): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        $words = array_filter($parts === false ? [] : $parts, static fn (string $word): bool => mb_strlen($word) >= 2);

        return array_slice(array_values($words), 0, self::MAX_WORDS);
    }

    /**
     * Todas las palabras deben aparecer, y cada una vale como comienzo de palabra («reembol» encuentra «reembolso»).
     *
     * @param  list<string>  $words
     */
    private function booleanTerms(array $words): string
    {
        return implode(' ', array_map(static fn (string $word): string => '+'.$word.'*', $words));
    }
}
