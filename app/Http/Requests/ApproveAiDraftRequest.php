<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Aprobar un borrador de la IA, tal cual o con el texto editado. Quien responde tickets puede hacerlo; un observador no.
 */
class ApproveAiDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        return $ticket instanceof Ticket && ($this->user()?->can('reply', $ticket) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return ['body' => ['nullable', 'string', 'max:'.TicketMessage::MAX_LENGTH]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['body.max' => __('ai.errors.draft_body'), 'body.string' => __('ai.errors.draft_body')];
    }

    /** El texto editado, o nulo si se envía el borrador tal como está. */
    public function editedBody(): ?string
    {
        $body = $this->input('body');

        return is_string($body) ? $body : null;
    }
}
