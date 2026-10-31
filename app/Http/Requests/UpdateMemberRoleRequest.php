<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRoleRequest extends FormRequest
{
    /**
     * Se autoriza antes de validar: quien no puede cambiar roles no debe aprender nada de las reglas.
     */
    public function authorize(): bool
    {
        $member = $this->route('member');

        return $member instanceof User && ($this->user()?->can('changeRole', $member) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::enum(Role::class)],
        ];
    }
}
