<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Crear un cliente: nombre y, si lo hay, un correo que no use otro cliente de la empresa. */
class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        $this->merge(['email' => is_string($email) && trim($email) !== '' ? mb_strtolower(trim($email)) : null]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/', 'regex:/\S/'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('customers', 'email')->where('tenant_id', app(TenantContext::class)->id())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => __('tickets.errors.customer_required'),
            'name.regex' => __('tickets.errors.customer_required'),
            'name.max' => __('tickets.errors.customer_name_max'),
            'name.not_regex' => __('tickets.errors.subject_single_line'),
            'email.email' => __('tickets.errors.customer_email_invalid'),
            'email.unique' => __('tickets.errors.customer_email_taken'),
        ];
    }
}
