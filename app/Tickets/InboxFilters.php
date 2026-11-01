<?php

declare(strict_types=1);

namespace App\Tickets;

use BackedEnum;
use Illuminate\Support\Carbon;

/**
 * La combinación de filtros, búsqueda y orden de la bandeja, ya limpia. Se construye a partir de la petición o de una
 * vista guardada; cualquier valor que no reconoce lo descarta en vez de pasarlo a una consulta.
 */
final readonly class InboxFilters
{
    /**
     * @param  list<TicketStatus>  $statuses
     * @param  list<TicketPriority>  $priorities
     */
    public function __construct(
        public InboxScope $scope = InboxScope::All,
        public array $statuses = [],
        public array $priorities = [],
        public ?int $agent = null,
        public ?int $tag = null,
        public ?string $from = null,
        public ?string $to = null,
        public ?string $search = null,
        public InboxSort $sort = InboxSort::Recent,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $scope = $data['scope'] ?? null;
        $sort = $data['sort'] ?? null;

        return new self(
            scope: is_string($scope) ? (InboxScope::tryFrom($scope) ?? InboxScope::All) : InboxScope::All,
            statuses: self::enums($data['status'] ?? null, TicketStatus::class),
            priorities: self::enums($data['priority'] ?? null, TicketPriority::class),
            agent: self::id($data['agent'] ?? null),
            tag: self::id($data['tag'] ?? null),
            from: self::date($data['from'] ?? null),
            to: self::date($data['to'] ?? null),
            search: self::search($data['q'] ?? null),
            sort: is_string($sort) ? (InboxSort::tryFrom($sort) ?? InboxSort::Recent) : InboxSort::Recent,
        );
    }

    /**
     * La forma que se guarda y que viaja en la dirección: solo lo que no es el valor por defecto.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'scope' => $this->scope === InboxScope::All ? null : $this->scope->value,
            'status' => $this->statuses === [] ? null : array_map(static fn (TicketStatus $status): string => $status->value, $this->statuses),
            'priority' => $this->priorities === [] ? null : array_map(static fn (TicketPriority $priority): string => $priority->value, $this->priorities),
            'agent' => $this->agent,
            'tag' => $this->tag,
            'from' => $this->from,
            'to' => $this->to,
            'q' => $this->search,
            'sort' => $this->sort === InboxSort::Recent ? null : $this->sort->value,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** Hay algo que limita la lista (el orden no cuenta: no quita tickets). */
    public function isFiltered(): bool
    {
        return $this->scope !== InboxScope::All || $this->statuses !== [] || $this->priorities !== [] || $this->agent !== null
            || $this->tag !== null || $this->from !== null || $this->to !== null || $this->search !== null;
    }

    /** Lo que se guarda en una vista: los filtros y el orden, sin una búsqueda suelta. */
    public function withoutSearch(): self
    {
        return new self($this->scope, $this->statuses, $this->priorities, $this->agent, $this->tag, $this->from, $this->to, null, $this->sort);
    }

    private static function id(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return is_string($value) && ctype_digit($value) && strlen($value) <= 18 && (int) $value > 0 ? (int) $value : null;
    }

    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            return null;
        }

        $parsed = Carbon::createFromFormat('!Y-m-d', $value);

        return $parsed !== null && $parsed->format('Y-m-d') === $value ? $value : null;
    }

    /**
     * Texto de búsqueda de entre 2 y 100 caracteres, sin espacios sobrantes. Un número de ticket puede ser de una
     * sola cifra, con o sin «#».
     */
    private static function search(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', $value));

        if (preg_match('/\A#?\d{1,9}\z/', $text) === 1) {
            return $text;
        }

        return mb_strlen($text) >= 2 ? mb_substr($text, 0, 100) : null;
    }

    /**
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return list<T>
     */
    private static function enums(mixed $value, string $enum): array
    {
        $values = is_array($value) ? $value : (is_string($value) ? [$value] : []);
        $found = [];

        foreach ($values as $item) {
            $case = is_string($item) ? $enum::tryFrom($item) : null;

            if ($case !== null && ! in_array($case, $found, true)) {
                $found[] = $case;
            }
        }

        return $found;
    }
}
