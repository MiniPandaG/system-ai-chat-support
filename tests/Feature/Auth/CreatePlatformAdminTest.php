<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;

function runCreateAdmin(string $email, string $password, string $confirmation): PendingCommand
{
    return test()->artisan('platform:create-admin')
        ->expectsQuestion('Nombre', 'Ada Lovelace')
        ->expectsQuestion('Correo', $email)
        ->expectsQuestion('Contraseña', $password)
        ->expectsQuestion('Repite la contraseña', $confirmation);
}

it('crea al administrador con la contraseña en Argon2id, correo verificado y auditoría', function (): void {
    $password = 'Mayus-'.Str::random(12).'7';

    runCreateAdmin('ada@example.com', $password, $password)->assertSuccessful();

    $admin = User::where('email', 'ada@example.com')->firstOrFail();

    expect($admin->is_platform_admin)->toBeTrue()
        ->and($admin->email_verified_at)->not->toBeNull()
        ->and($admin->password)->toStartWith('$argon2id$')
        ->and(AuditLog::where('event', AuditEvent::PlatformAdminCreated->value)->where('user_id', $admin->id)->exists())->toBeTrue();
});

it('rechaza una contraseña débil o que no coincide y no crea nada', function (): void {
    runCreateAdmin('debil@example.com', 'corta', 'corta')->assertFailed();
    runCreateAdmin('sinmayus@example.com', 'todominusculas123', 'todominusculas123')->assertFailed();
    runCreateAdmin('distinta@example.com', 'Valida-Larga-123', 'Valida-Larga-124')->assertFailed();

    expect(User::count())->toBe(0);
});

it('rechaza un correo que ya existe', function (): void {
    $existing = User::factory()->create();
    $password = 'Mayus-'.Str::random(12).'7';

    runCreateAdmin($existing->email, $password, $password)->assertFailed();

    expect(User::count())->toBe(1);
});

it('la contraseña nunca se recibe como argumento ni opción', function (): void {
    $definition = Artisan::all()['platform:create-admin']->getDefinition();

    expect($definition->getArguments())->toBe([])
        ->and($definition->hasOption('password'))->toBeFalse();
});

it('is_platform_admin no se puede asignar en masa', function (): void {
    expect(fn () => User::create([
        'name' => 'Intruso',
        'email' => 'intruso@example.com',
        'password' => Str::random(24),
        'is_platform_admin' => true,
    ]))->toThrow(MassAssignmentException::class);
});

it('los seeders no crean usuarios ni contraseñas por defecto', function (): void {
    $this->seed();

    expect(User::count())->toBe(0);
});
