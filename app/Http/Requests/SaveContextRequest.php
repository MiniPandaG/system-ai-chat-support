<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\KbContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Guardar el contexto de la empresa: texto de hasta 4000 caracteres. Vacío se admite (quita el contexto).
 */
class SaveContextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', KbContext::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:'.config()->integer('knowledge.context.max'), $this->utf8()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.max' => __('knowledge.errors.context_max', ['max' => number_format(config()->integer('knowledge.context.max'), 0, ',', '.')]),
        ];
    }

    public function contextText(): string
    {
        return $this->string('body')->toString();
    }

    /**
     * @return Closure(string, mixed, Closure(string): mixed): void
     */
    private function utf8(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                $fail(__('knowledge.errors.body_encoding'));
            }
        };
    }
}
