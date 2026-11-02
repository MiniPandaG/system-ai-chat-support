<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\WidgetOrigin;
use App\Widget\Origins;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * El sitio que se quiere permitir. Se acepta lo que escribe una persona («https://Mi-Tienda.com/») y se guarda en la
 * forma exacta que envía el navegador; lo que no es un origen admisible se rechaza diciendo qué falta.
 */
class StoreWidgetOriginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', WidgetOrigin::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return ['origin' => ['required', 'string', 'max:255']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'origin.required' => __('widget.page.errors.required'),
            'origin.string' => __('widget.page.errors.invalid'),
            'origin.max' => __('widget.page.errors.invalid'),
        ];
    }

    /** El origen canónico, o nulo si lo escrito no es un origen admisible. */
    public function normalizedOrigin(): ?string
    {
        return Origins::normalize($this->string('origin')->toString());
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $validator->errors()->has('origin') && $this->normalizedOrigin() === null) {
                $validator->errors()->add('origin', __('widget.page.errors.invalid'));
            }
        });
    }
}
