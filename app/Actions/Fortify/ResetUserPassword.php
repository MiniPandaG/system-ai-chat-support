<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * @param  mixed  $user
     * @param  array<string, mixed>  $input
     */
    public function reset($user, array $input): void
    {
        if (! $user instanceof User) {
            throw new InvalidArgumentException('Solo se restablece la contraseña de usuarios de la aplicación.');
        }

        Validator::make($input, ['password' => $this->passwordRules()])->validate();

        $user->forceFill(['password' => $input['password']])->save();

        // Quien tuviera la contraseña vieja pierde cualquier sesión abierta.
        DB::table('sessions')->where('user_id', $user->getAuthIdentifier())->delete();
    }
}
