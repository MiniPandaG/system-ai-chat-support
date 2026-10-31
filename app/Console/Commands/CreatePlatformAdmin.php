<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Fortify\PasswordValidationRules;
use App\Enums\AuditEvent;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreatePlatformAdmin extends Command
{
    use PasswordValidationRules;

    protected $signature = 'platform:create-admin';

    protected $description = 'Crea un administrador de la plataforma. Los datos se piden de forma interactiva; la contraseña nunca va como argumento.';

    public function handle(AuditLogger $audit): int
    {
        $name = text(label: 'Nombre', required: true);
        $email = text(label: 'Correo', required: true);
        $password = password(label: 'Contraseña', required: true, hint: 'Mínimo 10 caracteres, una mayúscula y un número.');
        $confirmation = password(label: 'Repite la contraseña', required: true);

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirmation],
            [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
                'password' => $this->passwordRules(),
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = new User(['name' => $name, 'email' => mb_strtolower($email), 'password' => $password]);
        $user->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();

        $audit->record(AuditEvent::PlatformAdminCreated, $user->id);

        $this->components->info('Administrador de la plataforma creado. Al iniciar sesión tendrá que activar la verificación en dos pasos.');

        return self::SUCCESS;
    }
}
