<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WidgetOrigin;
use App\Plans\Limit;
use App\Plans\PlanLimits;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** @return list<string> */
function originsOf(object $tenant): array
{
    return app(TenantContext::class)->run($tenant, fn () => WidgetOrigin::query()->orderBy('origin')->pluck('origin')->all());
}

describe('la pantalla del chat web', function (): void {
    it('el administrador ve el código para incrustar con su llave, los sitios y el estado', function (): void {
        $tenant = widgetTenant();
        $admin = widgetAdmin($tenant);

        $response = $this->actingAs($admin)->get('/settings/widget')->assertOk();

        $props = $response->viewData('page')['props'];
        expect($props['snippet'])->toBe('<script src="'.url('/widget.js').'" data-key="'.$tenant->widget_key.'" async></script>')
            ->and($props['enabled'])->toBeTrue()
            ->and(array_column($props['origins'], 'origin'))->toBe(['https://tienda-ejemplo.com'])
            ->and($props['max'])->toBe(10)
            ->and($props['copy']['title'])->toBe('Chat web');
    });

    it('sin sitios permitidos el chat figura apagado', function (): void {
        $admin = widgetAdmin();

        $props = $this->actingAs($admin)->get('/settings/widget')->assertOk()->viewData('page')['props'];

        expect($props['enabled'])->toBeFalse()->and($props['origins'])->toBe([]);
    });

    it('el código lleva la llave de su propia empresa y no la de otra', function (): void {
        $mine = widgetTenant();
        $theirs = widgetTenant();

        $snippet = $this->actingAs(widgetAdmin($mine))->get('/settings/widget')->viewData('page')['props']['snippet'];

        expect($snippet)->toContain($mine->widget_key)->not->toContain($theirs->widget_key);
    });

    it('solo el administrador de la empresa la abre', function (): void {
        $tenant = widgetTenant();

        $this->actingAs(ticketStaff($tenant, Role::Agent))->get('/settings/widget')->assertForbidden();
        $this->actingAs(ticketStaff($tenant, Role::Observer))->get('/settings/widget')->assertForbidden();
        $this->actingAs(ticketStaff($tenant, Role::Admin, disabled: true))->get('/settings/widget')->assertRedirect('/login');
        $this->get('/settings/widget')->assertRedirect('/login');
    });

    it('el personal de la plataforma no la abre', function (): void {
        $platform = User::factory()->create(['is_platform_admin' => true, 'tenant_id' => null, 'role' => null]);
        $platform->forceFill(['two_factor_secret' => encrypt('X'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()])->save();

        $this->actingAs($platform)->get('/settings/widget')->assertForbidden();
    });

    it('la barra lateral ofrece «Chat web» solo a quien puede abrirla', function (): void {
        $tenant = widgetTenant();

        $admin = $this->actingAs(widgetAdmin($tenant))->get('/account')->viewData('page')['props']['auth']['can'];
        $agent = $this->actingAs(ticketStaff($tenant, Role::Agent))->get('/account')->viewData('page')['props']['auth']['can'];

        expect($admin['widget'])->toBeTrue()->and($agent['widget'])->toBeFalse();
    });
});

describe('permitir un sitio', function (): void {
    it('guarda el origen en su forma exacta, activa el canal y lo deja auditado', function (): void {
        $tenant = widgetTenant(enabled: false);
        $admin = widgetAdmin($tenant);

        $this->actingAs($admin)->post('/settings/widget/origins', ['origin' => '  HTTPS://Mi-Tienda.com/  '])->assertRedirect();

        expect(originsOf($tenant))->toBe(['https://mi-tienda.com'])
            ->and(app(PlanLimits::class)->usage($tenant, Limit::Channels))->toBe(1);

        $log = AuditLog::query()->where('event', 'widget.origin_added')->sole();
        expect($log->user_id)->toBe($admin->id)->and($log->context)->toEqual(['origin_id' => app(TenantContext::class)->run($tenant, fn () => WidgetOrigin::query()->sole()->id), 'origin' => 'https://mi-tienda.com']);
    });

    it('una vez permitido, el marco se sirve en ese sitio', function (): void {
        $this->withoutVite();
        $tenant = widgetTenant(enabled: false);
        $this->actingAs(widgetAdmin($tenant))->post('/settings/widget/origins', ['origin' => 'https://mi-tienda.com'])->assertRedirect();
        auth()->logout();

        $this->get('/widget/frame?key='.$tenant->widget_key.'&parent='.urlencode('https://mi-tienda.com'))->assertOk();
    });

    it('rechaza lo que no es un origen admisible con el motivo, sin guardar nada', function (string $origin): void {
        $tenant = widgetTenant(enabled: false);

        $this->actingAs(widgetAdmin($tenant))->post('/settings/widget/origins', ['origin' => $origin])
            ->assertSessionHasErrors(['origin' => 'Escribe solo la dirección, como https://tu-empresa.com: con https, sin ruta, sin comodines y sin credenciales.']);

        expect(originsOf($tenant))->toBe([]);
    })->with([
        'http' => ['http://tienda.com'],
        'con ruta' => ['https://tienda.com/chat'],
        'comodín' => ['https://*.tienda.com'],
        'credenciales' => ['https://u:p@tienda.com'],
        'sin esquema' => ['tienda.com'],
        'javascript' => ['javascript:alert(1)'],
        'ip' => ['https://203.0.113.9'],
        'dos a la vez' => ["https://a.com\nhttps://b.com"],
    ]);

    it('exige la dirección', function (): void {
        $tenant = widgetTenant(enabled: false);

        $this->actingAs(widgetAdmin($tenant))->post('/settings/widget/origins', ['origin' => ''])
            ->assertSessionHasErrors(['origin' => 'Escribe la dirección de tu sitio.']);
        $this->actingAs(widgetAdmin($tenant))->post('/settings/widget/origins', ['origin' => ['x']])->assertSessionHasErrors('origin');
    });

    it('acepta http solo en localhost y 127.0.0.1, para probar', function (): void {
        $tenant = widgetTenant(enabled: false);
        $admin = widgetAdmin($tenant);

        $this->actingAs($admin)->post('/settings/widget/origins', ['origin' => 'http://localhost:3000'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/settings/widget/origins', ['origin' => 'http://127.0.0.1:9090'])->assertSessionHasNoErrors();

        expect(originsOf($tenant))->toBe(['http://127.0.0.1:9090', 'http://localhost:3000']);
    });

    it('un sitio repetido, aunque se escriba distinto, se rechaza', function (): void {
        $tenant = widgetTenant();
        $admin = widgetAdmin($tenant);

        $this->actingAs($admin)->post('/settings/widget/origins', ['origin' => 'HTTPS://TIENDA-EJEMPLO.COM:443/'])
            ->assertSessionHasErrors(['origin' => 'Ese sitio ya está permitido.']);

        expect(originsOf($tenant))->toBe(['https://tienda-ejemplo.com']);
    });

    it('tiene un máximo de sitios por empresa', function (): void {
        $tenant = widgetTenant(enabled: false);
        $admin = widgetAdmin($tenant);
        foreach (range(1, 10) as $number) {
            widgetOrigin($tenant, "https://sitio{$number}.com");
        }

        $this->actingAs($admin)->post('/settings/widget/origins', ['origin' => 'https://sitio11.com'])
            ->assertSessionHasErrors(['origin' => 'Ya permites el máximo de 10 sitios. Quita uno para agregar otro.']);

        expect(originsOf($tenant))->toHaveCount(10);
    });

    it('cada empresa puede permitir el mismo sitio', function (): void {
        $first = widgetTenant(enabled: false);
        $second = widgetTenant(enabled: false);

        $this->actingAs(widgetAdmin($first))->post('/settings/widget/origins', ['origin' => 'https://compartido.com'])->assertSessionHasNoErrors();
        $this->actingAs(widgetAdmin($second))->post('/settings/widget/origins', ['origin' => 'https://compartido.com'])->assertSessionHasNoErrors();

        expect(originsOf($first))->toBe(['https://compartido.com'])->and(originsOf($second))->toBe(['https://compartido.com']);
    });

    it('no lo hacen ni el agente, ni el observador, ni un administrador desactivado', function (): void {
        $tenant = widgetTenant(enabled: false);

        foreach ([ticketStaff($tenant, Role::Agent), ticketStaff($tenant, Role::Observer)] as $user) {
            $this->actingAs($user)->post('/settings/widget/origins', ['origin' => 'https://tienda.com'])->assertForbidden();
        }

        expect(originsOf($tenant))->toBe([]);
    });

    it('lo que el cliente intente fijar (empresa, identificador) se ignora', function (): void {
        $mine = widgetTenant(enabled: false);
        $theirs = widgetTenant(enabled: false);

        $this->actingAs(widgetAdmin($mine))->post('/settings/widget/origins', ['origin' => 'https://tienda.com', 'tenant_id' => $theirs->id, 'id' => 99])->assertSessionHasNoErrors();

        expect(originsOf($mine))->toBe(['https://tienda.com'])->and(originsOf($theirs))->toBe([]);
    });
});

describe('el canal y el plan', function (): void {
    it('encender el primer sitio cuenta como un canal; los siguientes no gastan otro', function (): void {
        $tenant = widgetTenant('inicial', enabled: false);
        $admin = widgetAdmin($tenant);
        $limits = app(PlanLimits::class);

        expect($limits->usage($tenant, Limit::Channels))->toBe(0);
        $this->actingAs($admin)->post('/settings/widget/origins', ['origin' => 'https://uno.com'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/settings/widget/origins', ['origin' => 'https://dos.com'])->assertSessionHasNoErrors();

        expect($limits->usage($tenant, Limit::Channels))->toBe(1)->and(originsOf($tenant))->toHaveCount(2);
    });

    it('si el plan no incluye canales no se puede encender y se explica', function (): void {
        $tenant = widgetTenant('inicial', enabled: false);
        DB::table('plans')->where('slug', 'inicial')->update(['max_channels' => 0]);

        $this->actingAs(widgetAdmin($tenant))->post('/settings/widget/origins', ['origin' => 'https://uno.com'])
            ->assertSessionHasErrors('limit');

        expect(originsOf($tenant))->toBe([]);
    });

    it('con el tope de canales alcanzado, apagar y volver a encender sigue siendo posible', function (): void {
        $tenant = widgetTenant('inicial');
        $admin = widgetAdmin($tenant);
        $origin = app(TenantContext::class)->run($tenant, fn () => WidgetOrigin::query()->sole());

        $this->actingAs($admin)->delete("/settings/widget/origins/{$origin->id}")->assertRedirect();
        $this->actingAs($admin)->post('/settings/widget/origins', ['origin' => 'https://nuevo.com'])->assertSessionHasNoErrors();

        expect(originsOf($tenant))->toBe(['https://nuevo.com']);
    });

    it('la pantalla del plan mide los canales', function (): void {
        $tenant = widgetTenant('inicial');

        $limits = $this->actingAs(widgetAdmin($tenant))->get('/settings/plan')->viewData('page')['props']['limits'];
        $channels = collect($limits)->firstWhere('key', 'channels');

        expect($channels['measured'])->toBeTrue()->and($channels['used'])->toBe(1)->and($channels['limit'])->toBe(1);
    });
});

describe('quitar un sitio', function (): void {
    it('lo quita, lo audita y, si era el último, apaga el widget y corta las sesiones abiertas', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant);
        $admin = widgetAdmin($tenant);
        $origin = app(TenantContext::class)->run($tenant, fn () => WidgetOrigin::query()->sole());

        $this->actingAs($admin)->delete("/settings/widget/origins/{$origin->id}")->assertRedirect();

        expect(originsOf($tenant))->toBe([])
            ->and(AuditLog::query()->where('event', 'widget.origin_removed')->sole()->context)->toEqual(['origin_id' => $origin->id, 'origin' => 'https://tienda-ejemplo.com']);
        widgetGet($this, 'conversation', $token)->assertUnauthorized();
        widgetSend($this, $tenant)->assertNotFound();
    });

    it('un sitio de otra empresa da 404 y no se toca', function (): void {
        $mine = widgetTenant();
        $theirs = widgetTenant();
        $theirOrigin = app(TenantContext::class)->run($theirs, fn () => WidgetOrigin::query()->sole());

        $this->actingAs(widgetAdmin($mine))->delete("/settings/widget/origins/{$theirOrigin->id}")->assertNotFound();

        expect(originsOf($theirs))->toBe(['https://tienda-ejemplo.com']);
    });

    it('no lo hacen el agente ni el observador', function (): void {
        $tenant = widgetTenant();
        $origin = app(TenantContext::class)->run($tenant, fn () => WidgetOrigin::query()->sole());

        foreach ([Role::Agent, Role::Observer] as $role) {
            $this->actingAs(ticketStaff($tenant, $role))->delete("/settings/widget/origins/{$origin->id}")->assertForbidden();
        }

        expect(originsOf($tenant))->toHaveCount(1);
    });

    it('un identificador que no es un número no llega a la consulta', function (): void {
        $tenant = widgetTenant();

        $this->actingAs(widgetAdmin($tenant))->delete('/settings/widget/origins/abc')->assertNotFound();
    });
});

describe('límites de la pantalla', function (): void {
    it('agregar sitios tiene un tope de peticiones por cuenta', function (): void {
        $tenant = widgetTenant(enabled: false);
        $admin = widgetAdmin($tenant);

        foreach (range(1, 30) as $_) {
            $this->actingAs($admin)->post('/settings/widget/origins', ['origin' => 'x']);
        }

        $this->actingAs($admin)->post('/settings/widget/origins', ['origin' => 'https://tienda.com'])->assertStatus(429);
    });
});
