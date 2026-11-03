<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Tickets\TicketPriority;
use App\Tickets\TicketStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Los parámetros de una lista: paginación por cursor y filtros de una lista cerrada. Lo que no es válido se rechaza con un
 * 422 que dice cuál es; ningún valor llega a la consulta sin pasar por aquí.
 */
class ListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.config()->integer('api.pagination.max')],
            'cursor' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', Rule::enum(TicketStatus::class)],
            'priority' => ['nullable', 'string', Rule::enum(TicketPriority::class)],
            'assigned_to' => ['nullable', 'regex:/\A(none|[1-9]\d{0,17})\z/'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'number' => ['nullable', 'integer', 'min:1'],
            'email' => ['nullable', 'string', 'max:255'],
            'q' => ['nullable', 'string', 'min:2', 'max:100'],
        ];
    }

    public function pageSize(): int
    {
        return $this->filled('limit') ? $this->integer('limit') : config()->integer('api.pagination.default');
    }

    public function cursor(): ?int
    {
        return $this->filled('cursor') ? $this->integer('cursor') : null;
    }
}
