<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Texto que se escribe dentro de un ticket (respuesta o nota). Es texto plano de hasta 10 000 caracteres; se permiten
 * saltos de línea y tabulaciones, pero ningún otro carácter de control. Nada se interpreta como HTML ni como Markdown.
 */
abstract class TicketBodyRequest extends FormRequest
{
    /** Habilidad de TicketPolicy que debe tener quien escribe. */
    abstract protected function ability(): string;

    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        return $ticket instanceof Ticket && ($this->user()?->can($this->ability(), $ticket) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.TicketMessage::MAX_LENGTH, 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => __('tickets.errors.body_required'),
            'body.string' => __('tickets.errors.body_required'),
            'body.max' => __('tickets.errors.body_max', ['max' => TicketMessage::MAX_LENGTH]),
            'body.not_regex' => __('tickets.errors.body_characters'),
        ];
    }
}
