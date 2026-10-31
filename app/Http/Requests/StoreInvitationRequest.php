<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\Invitation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Invitation::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'emails' => ['required', 'array', 'min:1', 'max:10'],
            'emails.*' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'string', Rule::enum(Role::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'emails.required' => __('team.invite_emails_required'),
            'emails.min' => __('team.invite_emails_required'),
            'emails.max' => __('team.invite_emails_max'),
            'emails.*.email' => __('team.invite_email_invalid'),
        ];
    }
}
