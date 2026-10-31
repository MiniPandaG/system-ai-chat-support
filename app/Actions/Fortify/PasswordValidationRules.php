<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /**
     * Mínimo 10 caracteres, una mayúscula y un número. No se consulta ninguna base externa de contraseñas filtradas.
     *
     * @return array<int, Rule|array<mixed>|string>
     */
    protected function passwordRules(): array
    {
        return ['required', 'string', Password::min(10)->mixedCase()->numbers(), 'confirmed'];
    }
}
