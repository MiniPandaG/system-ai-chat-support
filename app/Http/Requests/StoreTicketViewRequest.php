<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\TicketView;

/**
 * Guardar la combinación de filtros actual como vista: los mismos parámetros de la bandeja más un nombre y si se
 * comparte con el equipo.
 */
class StoreTicketViewRequest extends InboxRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', TicketView::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'name' => ['required', 'string', 'max:'.TicketView::MAX_NAME_LENGTH, 'not_regex:/[\x00-\x1F\x7F]/'],
            'shared' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'name.required' => __('tickets.errors.view_name_required'),
            'name.max' => __('tickets.errors.view_name_max', ['max' => TicketView::MAX_NAME_LENGTH]),
            'name.not_regex' => __('tickets.errors.subject_single_line'),
        ];
    }
}
