<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Api\ApiPermission;
use App\Models\ApiKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Crear una llave: nombre descriptivo, nivel de acceso y caducidad opcional (30, 90 o 365 días). Listas cerradas: nada más
 * se acepta.
 */
class StoreApiKeyRequest extends FormRequest
{
    public const EXPIRATIONS = [30, 90, 365];

    public function authorize(): bool
    {
        return $this->user()?->can('manage', ApiKey::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60', 'not_regex:/[\x00-\x1F\x7F]/', 'regex:/\S/'],
            'permission' => ['required', Rule::enum(ApiPermission::class)],
            'expires_in_days' => ['nullable', 'integer', Rule::in(self::EXPIRATIONS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => __('api.keys.errors.name_required'),
            'name.max' => __('api.keys.errors.name_max'),
            'name.not_regex' => __('api.keys.errors.name_invalid'),
            'name.regex' => __('api.keys.errors.name_required'),
            'permission.required' => __('api.keys.errors.permission'),
            'permission.enum' => __('api.keys.errors.permission'),
            'expires_in_days.in' => __('api.keys.errors.expiration'),
            'expires_in_days.integer' => __('api.keys.errors.expiration'),
        ];
    }

    public function keyName(): string
    {
        return trim($this->string('name')->toString());
    }

    public function apiPermission(): ApiPermission
    {
        return ApiPermission::from($this->string('permission')->toString());
    }

    public function expiresInDays(): ?int
    {
        return $this->filled('expires_in_days') ? $this->integer('expires_in_days') : null;
    }
}
