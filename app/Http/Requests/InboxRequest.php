<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Ticket;
use App\Tickets\InboxFilters;
use App\Tickets\InboxScope;
use App\Tickets\InboxSort;
use App\Tickets\TicketPriority;
use App\Tickets\TicketStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Los filtros, la búsqueda y el orden de la bandeja, que viajan en la dirección. Se validan con lista cerrada y,
 * una vez validados, se convierten en un `InboxFilters` que es lo único que llega a la consulta.
 */
class InboxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Ticket::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'scope' => ['nullable', 'string', Rule::enum(InboxScope::class)],
            'status' => ['nullable', 'array', 'max:'.count(TicketStatus::cases())],
            'status.*' => ['string', Rule::enum(TicketStatus::class)],
            'priority' => ['nullable', 'array', 'max:'.count(TicketPriority::cases())],
            'priority.*' => ['string', Rule::enum(TicketPriority::class)],
            'agent' => ['nullable', 'integer', 'min:1'],
            'tag' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'string', Rule::enum(InboxSort::class)],
            'saved' => ['nullable', 'integer', 'min:1'],
            'cursor' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'from.date_format' => __('tickets.errors.date_invalid'),
            'to.date_format' => __('tickets.errors.date_invalid'),
            'q.max' => __('tickets.errors.search_max'),
        ];
    }

    /** Los filtros de la petición ya limpios (sin tener en cuenta una vista guardada). */
    public function filters(): InboxFilters
    {
        return InboxFilters::fromArray($this->safe()->except(['saved', 'cursor']));
    }

    /** Identificador de la vista guardada pedida, si la hay. */
    public function savedId(): ?int
    {
        $saved = $this->validated('saved');

        return is_numeric($saved) ? (int) $saved : null;
    }
}
