<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\Ticket;
use App\Tenancy\TenantContext;
use App\Tickets\TicketPriority;
use App\Tickets\TicketStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cambiar estado, prioridad o quien atiende. Cada campo es opcional; quien atiende debe ser un administrador o
 * agente activo de la misma empresa, o nulo para dejarlo sin asignar.
 */
class UpdateTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        return $ticket instanceof Ticket && ($this->user()?->can('update', $ticket) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();

        return [
            'status' => ['sometimes', 'required', 'string', Rule::enum(TicketStatus::class)],
            'priority' => ['sometimes', 'required', 'string', Rule::enum(TicketPriority::class)],
            'assigned_to' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')
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
            'status.required' => __('tickets.errors.status_invalid'),
            'status.enum' => __('tickets.errors.status_invalid'),
            'priority.required' => __('tickets.errors.priority_invalid'),
            'priority.enum' => __('tickets.errors.priority_invalid'),
            'assigned_to.exists' => __('tickets.errors.assignee_invalid'),
        ];
    }
}
