<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeTenantPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('changePlan', Tenant::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')->where('is_active', true)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plan_id.required' => __('platform.errors.plan_required'),
            'plan_id.exists' => __('platform.errors.plan_unavailable'),
        ];
    }
}
