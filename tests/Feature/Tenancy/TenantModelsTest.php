<?php

declare(strict_types=1);

use App\Models\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Modelos con columna tenant_id que pueden no usar el filtro automático, cada uno con su motivo.
 *
 * @var array<class-string, string>
 */
const UNSCOPED_TENANT_MODELS = [
    User::class => 'Se consulta al iniciar sesión, antes de que exista una empresa en contexto; las consultas de equipo filtran tenant_id de forma explícita.',
];

it('todo modelo con columna tenant_id usa el filtro automático de empresa', function (): void {
    $violations = [];

    foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');

        if (! Schema::hasColumn((new $class)->getTable(), 'tenant_id') || array_key_exists($class, UNSCOPED_TENANT_MODELS)) {
            continue;
        }

        if (! in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
            $violations[] = $class;
        }
    }

    expect($violations)->toBe([]);
});

it('la columna tenant_id nunca es rellenable en masa', function (): void {
    foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');

        expect((new $class)->isFillable('tenant_id'))->toBeFalse();
    }
});

it('un usuario de empresa apunta a su empresa y el personal de la plataforma a ninguna', function (): void {
    $member = $this->userInTenant();
    $staff = User::factory()->create(['is_platform_admin' => true]);

    expect($member->tenant()->firstOrFail()->id)->toBe($member->tenant_id)
        ->and($staff->tenant_id)->toBeNull();
});
