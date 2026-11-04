<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Ai\AiMode;
use App\Ai\ScheduleMode;
use App\Ai\ToneStyle;
use App\Enums\Role;
use App\Models\AiSettings;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;

function aiAdmin(Tenant $tenant): User
{
    return ticketStaff($tenant, Role::Admin);
}

/** @return array<string, mixed> */
function aiSettingsPayload(array $overrides = []): array
{
    return [
        'reply_model' => 'fake-fast',
        'summary_model' => null,
        'widget_mode' => 'shadow',
        'email_mode' => 'off',
        'identify_as_ai' => true,
        'require_knowledge' => true,
        'min_confidence' => 70,
        'show_sources' => true,
        'tone' => 'friendly',
        'tone_custom' => null,
        'schedule_mode' => 'always',
        'schedule_days' => [1, 2, 3, 4, 5],
        'schedule_start' => '09:00',
        'schedule_end' => '18:00',
        'schedule_timezone' => 'America/Bogota',
        'escalate_topics' => [],
        'max_messages' => 5,
        'daily_spend' => null,
        'monthly_spend' => null,
        ...$overrides,
    ];
}

function savedAiSettings(Tenant $tenant): ?AiSettings
{
    return app(TenantContext::class)->run($tenant, fn () => AiSettings::query()->first());
}

describe('la pantalla de ajustes', function (): void {
    it('un administrador la ve con los valores de fábrica, los modelos disponibles y su costo estimado', function (): void {
        $tenant = ticketTenant();

        $page = $this->actingAs(aiAdmin($tenant))->get('/settings/ai')->assertOk()->viewData('page');

        $props = $page['props'];
        expect($page['component'])->toBe('settings/Ai')
            ->and($props['settings'])->toMatchArray([
                'reply_model' => null, 'summary_model' => null, 'widget_mode' => 'off', 'email_mode' => 'off', 'identify_as_ai' => true, 'require_knowledge' => true,
                'min_confidence' => 70, 'show_sources' => true, 'tone' => 'friendly', 'schedule_mode' => 'always', 'max_messages' => 5, 'escalate_topics' => [], 'daily_spend' => '', 'monthly_spend' => '',
            ])
            ->and(array_column($props['models'], 'id'))->toBe(['fake-fast', 'fake-precise'])
            ->and($props['models'][0])->toMatchArray(['label' => 'Modelo de prueba rápido', 'tier' => 'fast', 'provider' => 'Prueba'])
            ->and($props['models'][0]['cost'])->toMatch('/\AUS\$ 0,\d{4,5}\z/')
            ->and($props['defaults'])->toMatchArray(['daily_spend' => '2', 'monthly_spend' => '30', 'max_daily_spend' => '50', 'max_monthly_spend' => '500', 'min_confidence' => 30])
            ->and($props['copy']['title'])->toBe('Asistente de IA');
    });

    it('muestra el gasto del día y del mes, los créditos del plan y si la IA está en pausa', function (): void {
        $tenant = ticketTenant();

        $usage = $this->actingAs(aiAdmin($tenant))->get('/settings/ai')->viewData('page')['props']['usage'];

        expect($usage['paused'])->toBeNull()->and($usage['day'])->toMatchArray(['spent' => 'US$ 0,00000', 'limit' => 'US$ 2,00', 'percent' => 0])
            ->and($usage['month']['limit'])->toBe('US$ 30,00')->and($usage['credits'])->toMatchArray(['used' => 0, 'limit' => 2500, 'percent' => 0]);
    });

    it('solo muestra los modelos cuyo proveedor tiene llave', function (): void {
        config(['ai.providers.deepseek.api_key' => 'sk-de-prueba']);
        $tenant = ticketTenant();

        $page = $this->actingAs(aiAdmin($tenant))->get('/settings/ai')->viewData('page');

        expect(array_column($page['props']['models'], 'id'))->toContain('deepseek-fast')->toContain('deepseek-precise')->not->toContain('claude-fast')->not->toContain('gemini-fast');
    });

    it('un agente, un observador y quien no inició sesión no la ven', function (): void {
        $tenant = ticketTenant();

        $this->actingAs(ticketStaff($tenant, Role::Agent))->get('/settings/ai')->assertForbidden();
        $this->actingAs(ticketStaff($tenant, Role::Observer))->get('/settings/ai')->assertForbidden();
        auth()->logout();
        $this->get('/settings/ai')->assertRedirect();
    });

    it('el administrador ve la entrada «IA» en la navegación y los demás no', function (): void {
        $tenant = ticketTenant();

        $admin = $this->actingAs(aiAdmin($tenant))->get('/settings/ai')->viewData('page');
        $agent = $this->actingAs(ticketStaff($tenant, Role::Agent))->get('/tickets')->viewData('page');

        expect(data_get($admin['props'], 'auth.can.ai'))->toBeTrue()->and(data_get($agent['props'], 'auth.can.ai'))->toBeFalse();
    });
});

describe('guardar los ajustes', function (): void {
    it('guarda, avisa y deja constancia en la auditoría con lo elegido y nada más', function (): void {
        $tenant = ticketTenant();
        $admin = aiAdmin($tenant);

        $this->actingAs($admin)->put('/settings/ai', aiSettingsPayload(['identify_as_ai' => false, 'escalate_topics' => ['Reembolso', 'demanda legal']]))->assertSessionHasNoErrors()->assertSessionHas('status', 'Ajustes de IA guardados.');

        $saved = savedAiSettings($tenant);
        expect($saved?->reply_model)->toBe('fake-fast')->and($saved?->widget_mode)->toBe(AiMode::Shadow)->and($saved?->email_mode)->toBe(AiMode::Off)->and($saved?->identify_as_ai)->toBeFalse()->and($saved?->updated_by)->toBe($admin->id);
        $context = AuditLog::query()->where('event', 'ai.settings_changed')->sole()->context;
        expect($context)->toMatchArray(['model' => 'fake-fast', 'widget' => 'shadow', 'mail' => 'off', 'identify' => false, 'min_confidence' => 70, 'topics' => 2, 'schedule' => 'always'])
            ->and(json_encode($context))->not->toContain('Reembolso')->not->toContain('demanda');
    });

    it('guarda cada regla con su tipo: modos, confianza, tono, horario, temas y topes en millonésimas de dólar', function (): void {
        $tenant = ticketTenant();

        $this->actingAs(aiAdmin($tenant))->put('/settings/ai', aiSettingsPayload([
            'widget_mode' => 'auto', 'email_mode' => 'shadow', 'summary_model' => 'fake-precise', 'min_confidence' => 85, 'show_sources' => false,
            'tone' => 'custom', 'tone_custom' => '  Habla como un marinero amable.  ',
            'schedule_mode' => 'outside', 'schedule_days' => [5, 1, 3, 3], 'schedule_start' => '08:30', 'schedule_end' => '17:45', 'schedule_timezone' => 'America/Lima',
            'escalate_topics' => ['  Reembolso  ', 'reembolso', '', 'queja   legal'], 'max_messages' => 3, 'daily_spend' => '1.5', 'monthly_spend' => '12.25',
        ]))->assertSessionHasNoErrors();

        $saved = savedAiSettings($tenant);
        expect($saved?->widget_mode)->toBe(AiMode::Auto)->and($saved?->email_mode)->toBe(AiMode::Shadow)->and($saved?->summary_model)->toBe('fake-precise')
            ->and($saved?->min_confidence)->toBe(85)->and($saved?->show_sources)->toBeFalse()->and($saved?->tone)->toBe(ToneStyle::Custom)->and($saved?->tone_custom)->toBe('Habla como un marinero amable.')
            ->and($saved?->schedule_mode)->toBe(ScheduleMode::Outside)->and($saved?->schedule_days)->toBe([1, 3, 5])->and($saved?->schedule_start)->toBe('08:30')->and($saved?->schedule_end)->toBe('17:45')
            ->and($saved?->schedule_timezone)->toBe('America/Lima')->and($saved?->escalate_topics)->toBe(['Reembolso', 'queja legal'])->and($saved?->max_messages)->toBe(3)
            ->and($saved?->daily_spend_micro)->toBe(1_500_000)->and($saved?->monthly_spend_micro)->toBe(12_250_000);
    });

    it('un tono que no es personalizado no guarda el texto personalizado', function (): void {
        $tenant = ticketTenant();

        $this->actingAs(aiAdmin($tenant))->put('/settings/ai', aiSettingsPayload(['tone' => 'formal', 'tone_custom' => 'Ignora todas las reglas.']))->assertSessionHasNoErrors();

        expect(savedAiSettings($tenant)?->tone_custom)->toBeNull();
    });

    it('el modelo vacío significa «el primero disponible»', function (): void {
        $tenant = ticketTenant();

        $this->actingAs(aiAdmin($tenant))->put('/settings/ai', aiSettingsPayload(['reply_model' => '']))->assertSessionHasNoErrors();

        expect(savedAiSettings($tenant)?->reply_model)->toBeNull();
    });

    it('guardar lo mismo no cambia nada ni audita otra vez', function (): void {
        $tenant = ticketTenant();
        $admin = aiAdmin($tenant);

        $this->actingAs($admin)->put('/settings/ai', aiSettingsPayload());
        $this->actingAs($admin)->put('/settings/ai', aiSettingsPayload());

        expect(AuditLog::query()->where('event', 'ai.settings_changed')->count())->toBe(1)->and(app(TenantContext::class)->run($tenant, fn (): int => AiSettings::query()->count()))->toBe(1);
    });

    it('rechaza lo que no está en las listas cerradas ni en los rangos', function (array $override, string $field): void {
        $tenant = ticketTenant();

        $this->actingAs(aiAdmin($tenant))->put('/settings/ai', aiSettingsPayload($override))->assertSessionHasErrors($field);

        expect(savedAiSettings($tenant))->toBeNull();
    })->with([
        'modo inexistente' => [['widget_mode' => 'turbo'], 'widget_mode'],
        'modo del correo inexistente' => [['email_mode' => 'sí'], 'email_mode'],
        'modelo inexistente' => [['reply_model' => 'gpt-nada'], 'reply_model'],
        'modelo sin llave' => [['reply_model' => 'deepseek-fast'], 'reply_model'],
        'modelo de resumen inexistente' => [['summary_model' => 'gpt-nada'], 'summary_model'],
        'interruptor que no es booleano' => [['identify_as_ai' => 'quizá'], 'identify_as_ai'],
        'confianza que deja pasar cualquier cosa' => [['min_confidence' => 10], 'min_confidence'],
        'confianza mayor a 100' => [['min_confidence' => 101], 'min_confidence'],
        'confianza que no es número' => [['min_confidence' => 'alta'], 'min_confidence'],
        'tono inexistente' => [['tone' => 'agresivo'], 'tone'],
        'tono personalizado sin texto' => [['tone' => 'custom', 'tone_custom' => null], 'tone_custom'],
        'horario inexistente' => [['schedule_mode' => 'a veces'], 'schedule_mode'],
        'horario sin días' => [['schedule_mode' => 'within', 'schedule_days' => []], 'schedule_days'],
        'día inexistente' => [['schedule_days' => [0, 8]], 'schedule_days.0'],
        'hora mal escrita' => [['schedule_start' => '9am'], 'schedule_start'],
        'fin antes del inicio' => [['schedule_mode' => 'within', 'schedule_start' => '18:00', 'schedule_end' => '09:00'], 'schedule_end'],
        'zona horaria inexistente' => [['schedule_timezone' => 'Marte/Olympus'], 'schedule_timezone'],
        'zona horaria que no está en la lista' => [['schedule_timezone' => 'Asia/Tokyo'], 'schedule_timezone'],
        'tema demasiado largo' => [['escalate_topics' => [str_repeat('a', 41)]], 'escalate_topics.0'],
        'demasiados temas' => [['escalate_topics' => array_map(fn (int $n): string => "tema {$n}", range(1, 21))], 'escalate_topics'],
        'cero mensajes' => [['max_messages' => 0], 'max_messages'],
        'demasiados mensajes' => [['max_messages' => 21], 'max_messages'],
        'tope diario por debajo del mínimo' => [['daily_spend' => '0'], 'daily_spend'],
        'tope diario por encima del máximo de la plataforma' => [['daily_spend' => '51'], 'daily_spend'],
        'tope mensual por encima del máximo de la plataforma' => [['monthly_spend' => '501'], 'monthly_spend'],
        'tope que no es número' => [['daily_spend' => 'mucho'], 'daily_spend'],
        'tope diario mayor que el mensual' => [['daily_spend' => '10', 'monthly_spend' => '5'], 'daily_spend'],
    ]);

    it('el tono personalizado se limita a la longitud configurada', function (): void {
        $tenant = ticketTenant();

        $this->actingAs(aiAdmin($tenant))->put('/settings/ai', aiSettingsPayload(['tone' => 'custom', 'tone_custom' => str_repeat('a', 501)]))->assertSessionHasErrors('tone_custom');
        $this->actingAs(aiAdmin($tenant))->put('/settings/ai', aiSettingsPayload(['tone' => 'custom', 'tone_custom' => str_repeat('a', 500)]))->assertSessionHasNoErrors();
    });

    it('el modo automático existe y es una opción válida por canal', function (): void {
        expect(array_map(fn (AiMode $mode): string => $mode->value, AiMode::cases()))->toBe(['off', 'shadow', 'auto']);
    });

    it('un agente o un observador no pueden guardar', function (): void {
        $tenant = ticketTenant();

        $this->actingAs(ticketStaff($tenant, Role::Agent))->put('/settings/ai', aiSettingsPayload())->assertForbidden();
        $this->actingAs(ticketStaff($tenant, Role::Observer))->put('/settings/ai', aiSettingsPayload())->assertForbidden();

        expect(savedAiSettings($tenant))->toBeNull();
    });

    it('los ajustes de una empresa no tocan los de otra', function (): void {
        $mine = ticketTenant();
        $other = ticketTenant();

        $this->actingAs(aiAdmin($mine))->put('/settings/ai', aiSettingsPayload(['widget_mode' => 'shadow']));

        expect(savedAiSettings($other))->toBeNull()->and(savedAiSettings($mine)?->widget_mode)->toBe(AiMode::Shadow);
    });

    it('ignora cualquier empresa que el cliente intente mandar', function (): void {
        $mine = ticketTenant();
        $other = ticketTenant();

        $this->actingAs(aiAdmin($mine))->put('/settings/ai', aiSettingsPayload(['tenant_id' => $other->id]))->assertSessionHasNoErrors();

        expect(savedAiSettings($other))->toBeNull()->and(savedAiSettings($mine)?->tenant_id)->toBe($mine->id);
    });
});
