<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Plans\Limit;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

it('el catálogo inicial tiene los tres planes aprobados, guardados como datos', function (): void {
    $plans = Plan::query()->orderBy('id')->get()->keyBy('slug');

    expect($plans->keys()->all())->toBe(['inicial', 'equipo', 'negocio'])
        ->and([$plans['inicial']->max_agents, $plans['inicial']->max_channels, $plans['inicial']->max_tickets_per_month, $plans['inicial']->ai_credits_per_month])->toBe([2, 1, 100, 50])
        ->and([$plans['equipo']->max_agents, $plans['equipo']->max_channels, $plans['equipo']->max_tickets_per_month, $plans['equipo']->ai_credits_per_month])->toBe([5, 3, 1000, 500])
        ->and([$plans['negocio']->max_agents, $plans['negocio']->max_channels, $plans['negocio']->max_tickets_per_month, $plans['negocio']->ai_credits_per_month])->toBe([15, 5, 5000, 2500])
        ->and($plans->pluck('name')->all())->toBe(['Inicial', 'Equipo', 'Negocio']);
});

it('hay exactamente un plan por defecto y está activo', function (): void {
    $defaults = Plan::query()->where('is_default', true)->get();

    expect($defaults)->toHaveCount(1)
        ->and($defaults->first()?->slug)->toBe('inicial')
        ->and($defaults->first()?->is_active)->toBeTrue()
        ->and(Plan::default()->slug)->toBe('inicial');
});

it('cada límite del enum corresponde a una columna real de plans', function (): void {
    $plan = Plan::query()->where('slug', 'equipo')->firstOrFail();

    expect(Limit::cases())->toHaveCount(4);

    foreach (Limit::cases() as $limit) {
        expect($plan->limitFor($limit))->toBeInt();
    }

    expect(Limit::Agents->isMonthly())->toBeFalse()->and(Limit::Channels->isMonthly())->toBeFalse()
        ->and(Limit::TicketsPerMonth->isMonthly())->toBeTrue()->and(Limit::AiCredits->isMonthly())->toBeTrue();
});

it('un límite nulo es sin límite', function (): void {
    $plan = new Plan;
    $plan->setRawAttributes(['max_agents' => null, 'max_channels' => null, 'max_tickets_per_month' => null, 'ai_credits_per_month' => null]);

    foreach (Limit::cases() as $limit) {
        expect($plan->limitFor($limit))->toBeNull();
    }
});

it('los planes y el plan de una empresa no se pueden rellenar en masa', function (): void {
    foreach (['slug', 'name', 'is_default', 'is_active', 'max_agents', 'max_channels', 'max_tickets_per_month', 'ai_credits_per_month'] as $field) {
        expect((new Plan)->isFillable($field))->toBeFalse($field);
    }

    expect((new Tenant)->isFillable('plan_id'))->toBeFalse();
});

it('toda empresa tiene un plan, también las creadas con la fábrica', function (): void {
    $tenant = Tenant::factory()->create();

    expect($tenant->plan_id)->toBe(Plan::default()->id)
        ->and($tenant->plan()->firstOrFail()->slug)->toBe('inicial')
        ->and(Tenant::factory()->onPlan('negocio')->create()->plan()->firstOrFail()->slug)->toBe('negocio');
});

it('una empresa no puede quedarse sin plan ni apuntar a uno que no existe', function (): void {
    expect(fn () => Tenant::factory()->create(['plan_id' => null]))->toThrow(Exception::class);
    expect(fn () => Tenant::factory()->create(['plan_id' => 999999]))->toThrow(Exception::class);
});

it('un plan con empresas no se puede borrar', function (): void {
    $tenant = Tenant::factory()->onPlan('equipo')->create();

    expect(fn () => Plan::query()->where('slug', 'equipo')->delete())->toThrow(Exception::class)
        ->and($tenant->fresh()?->plan_id)->not->toBeNull();
});

function registrationPayload(array $extra = []): array
{
    $password = str_repeat('Pl', 6).'7';

    return [
        'name' => 'Ana Torres',
        'email' => 'ana-'.Str::random(8).'@empresa-ejemplo.com',
        'password' => $password,
        'password_confirmation' => $password,
        'company_name' => 'Soporte Ejemplo SAS',
        ...$extra,
    ];
}

it('una empresa que se registra empieza en el plan por defecto', function (): void {
    $this->post('/register', registrationPayload())->assertRedirect(route('verification.notice'));

    $admin = User::query()->latest('id')->firstOrFail();

    expect(Tenant::query()->findOrFail($admin->tenant_id)->plan_id)->toBe(Plan::default()->id);
});

it('el registro ignora cualquier plan o límite que envíe el cliente', function (): void {
    $negocio = Plan::query()->where('slug', 'negocio')->firstOrFail();

    $this->post('/register', registrationPayload(['plan_id' => $negocio->id, 'plan' => 'negocio', 'max_agents' => 999, 'limits' => ['agents' => 999]]))
        ->assertRedirect(route('verification.notice'));

    $admin = User::query()->latest('id')->firstOrFail();

    expect(Tenant::query()->findOrFail($admin->tenant_id)->plan_id)->toBe(Plan::default()->id)
        ->and(Plan::query()->where('slug', 'inicial')->value('max_agents'))->toBe(2);
});

it('ningún endpoint de la interfaz recibe un plan ni lo cambia', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->forTenant($tenant, Role::Admin)->create();
    $admin->forceFill(['two_factor_secret' => encrypt('S'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()])->save();
    $negocio = Plan::query()->where('slug', 'negocio')->firstOrFail();

    $this->actingAs($admin)->put('/settings/branding/name', ['name' => 'Nuevo nombre', 'plan_id' => $negocio->id])->assertSessionHasNoErrors();

    expect($tenant->fresh()?->plan_id)->toBe(Plan::default()->id);

    $planRoutes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route): bool => str_contains($route->uri(), 'plan') && $route->uri() !== 'settings/plan')->map(fn ($route): string => $route->uri())->all();

    expect($planRoutes)->toBe([]);
});

it('plans:list muestra el catálogo y el plan de cada empresa', function (): void {
    $tenant = Tenant::factory()->onPlan('equipo')->create(['name' => 'Empresa Listada']);

    $this->artisan('plans:list')
        ->expectsOutputToContain('inicial')
        ->expectsOutputToContain('negocio')
        ->expectsOutputToContain('Empresa Listada')
        ->assertSuccessful();

    expect($tenant->id)->toBeInt();
});

describe('plans:assign', function (): void {
    it('asigna el plan tras confirmar y lo audita sin datos personales', function (): void {
        $tenant = Tenant::factory()->create(['name' => 'Empresa Cambiada']);

        $this->artisan('plans:assign', ['tenant' => $tenant->id, 'plan' => 'equipo'])
            ->expectsConfirmation('¿Pasar «Empresa Cambiada» del plan inicial al plan equipo?', 'yes')
            ->expectsOutputToContain('ahora tiene el plan equipo')
            ->assertSuccessful();

        expect($tenant->fresh()?->plan()->firstOrFail()->slug)->toBe('equipo');

        $log = AuditLog::query()->where('event', AuditEvent::PlanAssigned->value)->firstOrFail();

        expect($log->context)->toEqual(['tenant_id' => $tenant->id, 'from' => 'inicial', 'to' => 'equipo', 'via' => 'console'])
            ->and(json_encode($log->getAttributes()))->not->toContain('Empresa Cambiada');
    });

    it('si se responde que no, no cambia nada', function (): void {
        $tenant = Tenant::factory()->create();

        $this->artisan('plans:assign', ['tenant' => $tenant->id, 'plan' => 'negocio'])
            ->expectsConfirmation('¿Pasar «'.$tenant->name.'» del plan inicial al plan negocio?', 'no')
            ->expectsOutputToContain('no se cambió nada')
            ->assertSuccessful();

        expect($tenant->fresh()?->plan_id)->toBe(Plan::default()->id)
            ->and(AuditLog::query()->where('event', AuditEvent::PlanAssigned->value)->exists())->toBeFalse();
    });

    it('sin interacción asigna directamente, para scripts', function (): void {
        $tenant = Tenant::factory()->create();

        $this->artisan('plans:assign', ['tenant' => $tenant->id, 'plan' => 'negocio', '--no-interaction' => true])->assertSuccessful();

        expect($tenant->fresh()?->plan()->firstOrFail()->slug)->toBe('negocio');
    });

    it('asignar el plan que ya tiene no cambia nada ni audita', function (): void {
        $tenant = Tenant::factory()->create();

        $this->artisan('plans:assign', ['tenant' => $tenant->id, 'plan' => 'inicial'])
            ->expectsOutputToContain('No se cambia nada')
            ->assertSuccessful();

        expect(AuditLog::query()->where('event', AuditEvent::PlanAssigned->value)->exists())->toBeFalse();
    });

    it('rechaza una empresa o un plan que no existen', function (string $tenantId, string $plan, string $message): void {
        $tenant = Tenant::factory()->create();
        $id = $tenantId === 'real' ? (string) $tenant->id : $tenantId;

        $this->artisan('plans:assign', ['tenant' => $id, 'plan' => $plan, '--no-interaction' => true])
            ->expectsOutputToContain($message)
            ->assertFailed();

        expect($tenant->fresh()?->plan_id)->toBe(Plan::default()->id);
    })->with([
        'empresa inexistente' => ['999999', 'equipo', 'No existe una empresa'],
        'empresa con texto' => ['abc', 'equipo', 'No existe una empresa'],
        'plan inexistente' => ['real', 'platino', 'No existe ese plan'],
    ]);

    it('no asigna un plan desactivado', function (): void {
        $tenant = Tenant::factory()->create();
        Plan::query()->where('slug', 'equipo')->update(['is_active' => false]);

        $this->artisan('plans:assign', ['tenant' => $tenant->id, 'plan' => 'equipo', '--no-interaction' => true])
            ->expectsOutputToContain('no está activo')
            ->assertFailed();

        expect($tenant->fresh()?->plan_id)->toBe(Plan::default()->id);
    });

    it('una empresa que ya tenía un plan desactivado lo conserva', function (): void {
        $tenant = Tenant::factory()->onPlan('equipo')->create();
        Plan::query()->where('slug', 'equipo')->update(['is_active' => false]);

        expect($tenant->fresh()?->plan()->firstOrFail()->slug)->toBe('equipo');
    });
});

it('los comandos de planes son de consola: no hay ninguna ruta web que los invoque', function (): void {
    $actions = collect(Route::getRoutes()->getRoutes())->map(fn ($route): string => $route->getActionName())->implode(' ');

    expect($actions)->not->toContain('AssignPlan')->and($actions)->not->toContain('ListPlans');
});
