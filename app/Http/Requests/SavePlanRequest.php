<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Crear o editar un plan del catálogo. Un límite vacío significa «sin límite».
 */
class SavePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', Plan::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $plan = $this->route('plan');
        $limit = ['nullable', 'integer', 'min:0', 'max:1000000'];

        return [
            'slug' => $plan instanceof Plan ? ['prohibited'] : ['required', 'string', 'min:2', 'max:30', 'regex:/\A[a-z0-9]+(-[a-z0-9]+)*\z/', Rule::unique('plans', 'slug')],
            'name' => ['required', 'string', 'min:2', 'max:60', 'regex:/\A[^\x00-\x1F\x7F]+\z/u'],
            'is_active' => ['required', 'boolean'],
            'max_agents' => $limit,
            'max_channels' => $limit,
            'max_tickets_per_month' => $limit,
            'ai_credits_per_month' => $limit,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => __('platform.errors.slug_format'),
            'slug.unique' => __('platform.errors.slug_taken'),
            'slug.prohibited' => __('platform.errors.slug_fixed'),
            'name.regex' => __('platform.errors.name_format'),
        ];
    }
}
