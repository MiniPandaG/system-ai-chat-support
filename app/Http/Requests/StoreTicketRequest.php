<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Tenancy\TenantContext;
use App\Tickets\TicketPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Abrir un ticket: el cliente es uno existente de la empresa o uno nuevo. Todo lo que apunta a otro registro se
 * comprueba contra la empresa en contexto.
 */
class StoreTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Ticket::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('customer_email');

        $this->merge([
            'customer_email' => is_string($email) && trim($email) !== '' ? mb_strtolower(trim($email)) : null,
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();

        return [
            'subject' => ['required', 'string', 'max:150', 'not_regex:/[\x00-\x1F\x7F]/'],
            'description' => ['required', 'string', 'max:'.TicketMessage::MAX_LENGTH, 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/'],
            'priority' => ['required', 'string', Rule::enum(TicketPriority::class)],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)],
            'customer_name' => ['nullable', 'required_without:customer_id', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/'],
            'customer_email' => ['nullable', 'email', 'max:255', Rule::unique('customers', 'email')->where('tenant_id', $tenantId)],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')
                ->where('tenant_id', $tenantId)
                ->whereNull('disabled_at')
                ->whereIn('role', [Role::Admin->value, Role::Agent->value])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'subject.required' => __('tickets.errors.subject_required'),
            'subject.max' => __('tickets.errors.subject_max'),
            'subject.not_regex' => __('tickets.errors.subject_single_line'),
            'description.required' => __('tickets.errors.description_required'),
            'description.max' => __('tickets.errors.body_max', ['max' => TicketMessage::MAX_LENGTH]),
            'description.not_regex' => __('tickets.errors.body_characters'),
            'priority.required' => __('tickets.errors.priority_invalid'),
            'priority.enum' => __('tickets.errors.priority_invalid'),
            'customer_id.exists' => __('tickets.errors.customer_missing'),
            'customer_name.required_without' => __('tickets.errors.customer_required'),
            'customer_name.max' => __('tickets.errors.customer_name_max'),
            'customer_name.not_regex' => __('tickets.errors.subject_single_line'),
            'customer_email.email' => __('tickets.errors.customer_email_invalid'),
            'customer_email.unique' => __('tickets.errors.customer_email_taken'),
            'assigned_to.exists' => __('tickets.errors.assignee_invalid'),
        ];
    }
}
