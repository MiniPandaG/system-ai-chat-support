<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Tag;
use App\Models\Ticket;
use App\Tenancy\TenantContext;
use App\Tickets\TagTone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Etiquetar un ticket con una etiqueta de la empresa (tag_id) o con una nueva (name y tone). El nombre se limpia de
 * espacios repetidos antes de validar; el tono sale de una lista cerrada.
 */
class AttachTicketTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        return $ticket instanceof Ticket && ($this->user()?->can('tag', $ticket) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');

        $this->merge([
            'name' => is_string($name) ? trim((string) preg_replace('/\s+/u', ' ', $name)) : $name,
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();

        return [
            'tag_id' => ['nullable', 'integer', Rule::exists('tags', 'id')->where('tenant_id', $tenantId)],
            'name' => ['nullable', 'required_without:tag_id', 'string', 'max:'.Tag::MAX_NAME_LENGTH, 'not_regex:/[\x00-\x1F\x7F]/'],
            'tone' => ['nullable', 'string', Rule::enum(TagTone::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tag_id.exists' => __('tickets.errors.tag_missing'),
            'name.required_without' => __('tickets.errors.tag_name_required'),
            'name.max' => __('tickets.errors.tag_name_max', ['max' => Tag::MAX_NAME_LENGTH]),
            'name.not_regex' => __('tickets.errors.subject_single_line'),
            'tone.enum' => __('tickets.errors.tag_tone_invalid'),
        ];
    }
}
