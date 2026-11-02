<?php

declare(strict_types=1);

namespace App\Http\Requests\Widget;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Lo único que un visitante puede enviar: un identificador de mensaje que genera su navegador, el texto plano y, si
 * quiere, su nombre y su correo. La llave del widget solo se pide al abrir la conversación (sin token todavía).
 */
class StoreWidgetMessageRequest extends FormRequest
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
        $control = 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/';

        return [
            'key' => [$this->bearerToken() === null ? 'required' : 'nullable', 'string', 'size:32', 'alpha_num:ascii'],
            'client_id' => ['required', 'uuid'],
            'body' => ['required', 'string', 'max:'.config()->integer('widget.message_max_length'), $control, 'regex:/\S/'],
            'name' => ['nullable', 'string', 'max:'.config()->integer('widget.name_max_length'), $control, 'regex:/\S/'],
            'email' => ['nullable', 'string', 'max:'.config()->integer('widget.email_max_length'), 'email:rfc'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => __('widget.errors.body_required'),
            'body.regex' => __('widget.errors.body_required'),
            'body.max' => __('widget.errors.body_max', ['max' => config()->integer('widget.message_max_length')]),
            'body.not_regex' => __('widget.errors.body_characters'),
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
