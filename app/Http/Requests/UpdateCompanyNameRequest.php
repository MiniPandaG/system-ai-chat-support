<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\TenantBranding;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyNameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', TenantBranding::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->string('name')->toString())]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:120']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['name.required' => __('validation.company_required')];
    }
}
