<?php

declare(strict_types=1);

use App\Actions\RegisterTenant;
use App\Enums\AuditEvent;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/**
 * @return array<string, string>
 */
function registrationData(array $overrides = []): array
{
    $password = 'Nueva-'.Str::random(12).'8';

    return [
        'name' => 'Ana Torres',
        'email' => 'ana-'.Str::random(8).'@empresa-ejemplo.com',
        'password' => $password,
        'password_confirmation' => $password,
        'company_name' => 'Soporte Ejemplo SAS',
        ...$overrides,
    ];
}

it('muestra la pantalla de registro en español', function (): void {
    $this->get('/register')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/Register')
            ->where('copy.title', 'Registra tu empresa')
            ->where('copy.submit', 'Crear mi empresa'));
});

it('crea la empresa y su administrador, abre la sesión y pide verificar el correo', function (): void {
    Notification::fake();
    $data = registrationData(['email' => 'ANA@Empresa-Ejemplo.com']);

    $this->post('/register', $data)->assertRedirect(route('verification.notice'));

    $admin = User::query()->where('email', 'ana@empresa-ejemplo.com')->firstOrFail();
    $tenant = Tenant::query()->findOrFail($admin->tenant_id);

    expect($tenant->name)->toBe('Soporte Ejemplo SAS')
        ->and($admin->role)->toBe(Role::Admin)
        ->and($admin->is_platform_admin)->toBeFalse()
        ->and($admin->email_verified_at)->toBeNull()
        ->and($admin->password)->toStartWith('$argon2id$')
        ->and(Hash::check($data['password'], $admin->password))->toBeTrue();

    $this->assertAuthenticatedAs($admin);
    Notification::assertSentTo($admin, VerifyEmailNotification::class);
});

it('un administrador sin verificar el correo no pasa de la pantalla de verificación', function (): void {
    $this->post('/register', registrationData());

    $this->get('/account')->assertRedirect(route('verification.notice'));
});

it('un administrador con el correo verificado y sin 2FA solo llega a la pantalla de seguridad', function (): void {
    $this->post('/register', registrationData());
    $admin = User::query()->latest('id')->firstOrFail();
    $admin->forceFill(['email_verified_at' => now()])->save();

    // La sesión guarda la instancia de la petición anterior: se vuelve a cargar el usuario ya verificado.
    $this->actingAs($admin->fresh() ?? $admin)->get('/account')->assertRedirect(route('security'));
});

it('audita la creación de la empresa sin datos personales', function (): void {
    $data = registrationData();

    $this->post('/register', $data);

    $admin = User::query()->latest('id')->firstOrFail();
    $log = AuditLog::query()->where('event', AuditEvent::TenantCreated->value)->where('user_id', $admin->id)->firstOrFail();
    $dump = (string) json_encode($log->getAttributes());

    expect($log->context)->toBe(['tenant_id' => $admin->tenant_id])
        ->and($dump)->not->toContain($data['email'])
        ->and($dump)->not->toContain($data['password'])
        ->and($dump)->not->toContain($data['name']);
});

it('dos empresas quedan separadas, cada una con su administrador', function (): void {
    $this->post('/register', registrationData(['company_name' => 'Empresa Uno']));
    auth()->logout();
    $this->post('/register', registrationData(['company_name' => 'Empresa Dos']));

    $admins = User::query()->whereNotNull('tenant_id')->get();

    expect(Tenant::query()->count())->toBe(2)
        ->and($admins)->toHaveCount(2)
        ->and($admins->pluck('tenant_id')->unique())->toHaveCount(2);
});

it('rechaza los campos vacíos, demasiado largos o con formato incorrecto y no crea nada', function (array $override, string $field): void {
    $before = [Tenant::query()->count(), User::query()->count()];

    $this->post('/register', registrationData($override))->assertSessionHasErrors($field);

    expect([Tenant::query()->count(), User::query()->count()])->toBe($before);
})->with([
    'sin nombre' => [['name' => ''], 'name'],
    'nombre larguísimo' => [['name' => str_repeat('a', 121)], 'name'],
    'sin correo' => [['email' => ''], 'email'],
    'correo inválido' => [['email' => 'no-es-un-correo'], 'email'],
    'sin empresa' => [['company_name' => ''], 'company_name'],
    'empresa larguísima' => [['company_name' => str_repeat('e', 121)], 'company_name'],
    'contraseña corta' => [['password' => 'Ab1', 'password_confirmation' => 'Ab1'], 'password'],
    'contraseña sin mayúscula' => [['password' => 'todominusculas123', 'password_confirmation' => 'todominusculas123'], 'password'],
    'confirmación distinta' => [['password_confirmation' => str_repeat('Zz', 6).'9'], 'password'],
    'campos que no son texto' => [['company_name' => ['x']], 'company_name'],
]);

it('dice en español que el correo ya tiene cuenta y no crea otra empresa', function (): void {
    $existing = User::factory()->create();
    $before = Tenant::query()->count();

    $this->post('/register', registrationData(['email' => $existing->email]))
        ->assertSessionHasErrors(['email' => 'Ya existe una cuenta con ese correo.']);

    expect(Tenant::query()->count())->toBe($before);
});

it('un correo repetido con otras mayúsculas también cuenta como existente', function (): void {
    $existing = User::factory()->create(['email' => 'persona@empresa-ejemplo.com']);

    $this->post('/register', registrationData(['email' => 'PERSONA@empresa-ejemplo.com']))->assertSessionHasErrors('email');

    expect(User::query()->where('email', $existing->email)->count())->toBe(1);
});

it('ignora los campos que intentan fijar rol, empresa o administración de la plataforma', function (): void {
    $victim = Tenant::factory()->create();

    $this->post('/register', registrationData([
        'role' => 'observer',
        'tenant_id' => (string) $victim->id,
        'is_platform_admin' => '1',
    ]))->assertRedirect(route('verification.notice'));

    $admin = User::query()->latest('id')->firstOrFail();

    expect($admin->role)->toBe(Role::Admin)
        ->and($admin->is_platform_admin)->toBeFalse()
        ->and($admin->tenant_id)->not->toBe($victim->id)
        ->and(User::query()->where('tenant_id', $victim->id)->count())->toBe(0);
});

it('si falla la creación del administrador no queda una empresa huérfana', function (): void {
    $existing = User::factory()->create();
    $before = Tenant::query()->count();

    // Salta la validación del formulario, como ocurriría con dos registros simultáneos del mismo correo.
    expect(fn () => app(RegisterTenant::class)('Empresa fantasma', 'Ana', $existing->email, 'Nueva-Segura-81x'))
        ->toThrow(QueryException::class);

    expect(Tenant::query()->count())->toBe($before);
});

it('limita a cinco registros por hora desde una misma dirección', function (): void {
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->post('/register', registrationData(['name' => '']))->assertSessionHasErrors('name');
    }

    $this->post('/register', registrationData())->assertSessionHasErrors('email');

    expect(session('errors')?->first('email'))->toContain('Demasiados intentos')
        ->and(Tenant::query()->count())->toBe(0);
});

it('limita a tres intentos por hora con el mismo correo, aunque cambie la dirección', function (): void {
    $email = 'objetivo@empresa-ejemplo.com';

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$attempt])
            ->post('/register', registrationData(['email' => $email, 'name' => '']))
            ->assertSessionHasErrors('name');
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
        ->post('/register', registrationData(['email' => $email]))
        ->assertSessionHasErrors('email');

    expect(session('errors')?->first('email'))->toContain('Demasiados intentos');
});

it('quien ya tiene sesión no puede volver a registrar', function (): void {
    $this->actingAs(User::factory()->create())->get('/register')->assertRedirect('/');
});

it('la sesión del nuevo administrador es una sesión nueva', function (): void {
    $before = Str::random(40);

    $this->withCookie(config()->string('session.cookie'), $before)->post('/register', registrationData());

    expect(session()->getId())->not->toBe($before);
});
