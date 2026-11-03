<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Models\TicketMessage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Añadir un mensaje a un ticket: texto plano de hasta 10 000 caracteres, del cliente (por defecto) o del equipo.
 */
class StoreMessageRequest extends FormRequest
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
            'body' => ['required', 'string', 'max:'.TicketMessage::MAX_LENGTH, 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', 'regex:/\S/'],
            'author' => ['nullable', 'string', Rule::in(['customer', 'agent'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => __('tickets.errors.body_required'),
            'body.regex' => __('tickets.errors.body_required'),
            'body.max' => __('tickets.errors.body_max', ['max' => TicketMessage::MAX_LENGTH]),
            'body.not_regex' => __('tickets.errors.body_characters'),
            'author.in' => __('api.errors.author_invalid'),
        ];
    }
}
