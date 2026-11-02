<?php

declare(strict_types=1);

namespace App\Http\Requests\Widget;

use Illuminate\Foundation\Http\FormRequest;

/**
 * El nombre y el correo que el visitante deja después de empezar a escribir. Alguno de los dos es obligatorio.
 */
class StoreWidgetContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required_without:email', 'nullable', 'string', 'max:'.config()->integer('widget.name_max_length'), 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', 'regex:/\S/'],
            'email' => ['required_without:name', 'nullable', 'string', 'max:'.config()->integer('widget.email_max_length'), 'email:rfc'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required_without' => __('widget.errors.contact_required'),
            'email.required_without' => __('widget.errors.contact_required'),
            'name.max' => __('widget.errors.name_max', ['max' => config()->integer('widget.name_max_length')]),
            'name.not_regex' => __('widget.errors.name_invalid'),
            'name.regex' => __('widget.errors.name_invalid'),
            'email.email' => __('widget.errors.email_invalid'),
            'email.max' => __('widget.errors.email_invalid'),
        ];
    }

    public function name(): ?string
    {
        $value = $this->input('name');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function email(): ?string
    {
        $value = $this->input('email');

        return is_string($value) && trim($value) !== '' ? mb_strtolower(trim($value)) : null;
    }
}
