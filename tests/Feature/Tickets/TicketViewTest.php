<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\TicketView;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tickets\TicketStatus;
use Illuminate\Support\Collection;

/** @return Collection<int, TicketView> */
function viewsOf(Tenant $tenant): Collection
{
    return app(TenantContext::class)->run($tenant, fn () => TicketView::query()->orderBy('id')->get());
}

function makeView(User $owner, array $attributes = []): TicketView
{
    return app(TenantContext::class)->run($owner->tenant()->firstOrFail(), fn (): TicketView => TicketView::factory()->ownedBy($owner)->create($attributes));
}

describe('guardar una vista', function (): void {
    it('guarda los filtros actuales con su nombre, privada por defecto, y lleva a la vista', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);

        $response = $this->actingAs($agent)->post('/tickets/views', [
            'name' => 'Urgentes abiertos',
            'status' => ['open'],
            'priority' => ['urgent', 'high'],
            'sort' => 'priority',
        ]);

        $view = viewsOf($tenant)->sole();
        $response->assertRedirect(route('tickets', ['saved' => $view->id]))->assertSessionHas('status', 'Vista guardada.');
        expect($view)->name->toBe('Urgentes abiertos')->user_id->toBe($agent->id)->shared->toBeFalse()
            ->and($view->filters)->toEqual(['status' => ['open'], 'priority' => ['urgent', 'high'], 'sort' => 'priority']);
    });

    it('se puede compartir con el equipo', function (): void {
        $tenant = ticketTenant();

        $this->actingAs(ticketStaff($tenant))->post('/tickets/views', ['name' => 'Del equipo', 'scope' => 'unassigned', 'shared' => true])->assertRedirect();

        expect(viewsOf($tenant)->sole())->shared->toBeTrue();
    });

    it('una búsqueda suelta no se guarda: la vista guarda filtros y orden', function (): void {
        $tenant = ticketTenant();

        $this->actingAs(ticketStaff($tenant))->post('/tickets/views', ['name' => 'Con búsqueda', 'status' => ['open'], 'q' => 'reembolso'])->assertRedirect();

        expect(viewsOf($tenant)->sole()->filters)->toBe(['status' => ['open']]);
    });

    it('sin ningún filtro no hay nada que guardar', function (): void {
        $tenant = ticketTenant();

        $this->actingAs(ticketStaff($tenant))->post('/tickets/views', ['name' => 'Vacía'])->assertSessionHasErrors('name');
        $this->actingAs(ticketStaff($tenant))->post('/tickets/views', ['name' => 'Solo búsqueda', 'q' => 'algo'])->assertSessionHasErrors('name');
        $this->actingAs(ticketStaff($tenant))->post('/tickets/views', ['name' => 'Solo orden', 'sort' => 'oldest'])->assertSessionHasErrors('name');

        expect(viewsOf($tenant))->toHaveCount(0);
    });

    it('guarda solo valores ya normalizados: los desconocidos nunca llegan a la tabla', function (): void {
        $tenant = ticketTenant();

        $this->actingAs(ticketStaff($tenant))->post('/tickets/views', ['name' => 'Limpia', 'status' => ['open'], 'sql' => 'DROP TABLE tickets', 'tenant_id' => 987654321, 'user_id' => 987654321, 'filters' => ['evil' => true]])->assertRedirect();

        $view = viewsOf($tenant)->sole();
        expect($view->filters)->toBe(['status' => ['open']])->and($view->tenant_id)->toBe($tenant->id)->and($view->user_id)->not->toBe(987654321);
    });

    it('valida el nombre', function (mixed $name): void {
        $tenant = ticketTenant();

        $this->actingAs(ticketStaff($tenant))->post('/tickets/views', ['name' => $name, 'status' => ['open']])->assertSessionHasErrors('name');

        expect(viewsOf($tenant))->toHaveCount(0);
    })->with([
        'vacío' => [''],
        'solo espacios' => ['   '],
        'sin campo' => [null],
        '61 caracteres' => [str_repeat('a', 61)],
        'salto de línea' => ["a\nb"],
        'una lista' => [['a']],
    ]);

    it('acepta un nombre de exactamente 60 caracteres y lo guarda como texto', function (): void {
        $tenant = ticketTenant();

        $this->actingAs(ticketStaff($tenant))->post('/tickets/views', ['name' => str_repeat('a', 60), 'status' => ['open']])->assertRedirect();
        $this->actingAs(ticketStaff($tenant))->post('/tickets/views', ['name' => '<b>x</b>', 'status' => ['open']])->assertRedirect();

        expect(viewsOf($tenant)->last())->name->toBe('<b>x</b>');
    });

    it('rechaza filtros que no son de la lista cerrada', function (): void {
        $tenant = ticketTenant();

        $this->actingAs(ticketStaff($tenant))->post('/tickets/views', ['name' => 'Mala', 'status' => ['hackeado']])->assertSessionHasErrors('status.0');

        expect(viewsOf($tenant))->toHaveCount(0);
    });

    it('hasta 10 vistas privadas por persona, y las de otra persona no cuentan', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $colleague = ticketStaff($tenant);
        foreach (range(1, 10) as $i) {
            makeView($agent, ['name' => "Vista {$i}"]);
        }
        makeView($colleague, ['name' => 'De otra persona']);

        $response = $this->actingAs($agent)->post('/tickets/views', ['name' => 'Once', 'status' => ['open']]);

        $response->assertSessionHasErrors('name');
        expect(session('errors')?->first('name'))->toBe('Ya tienes 10 vistas privadas, el máximo. Elimina alguna para crear otra.');
        $this->actingAs($colleague)->post('/tickets/views', ['name' => 'Su segunda', 'status' => ['open']])->assertSessionHasNoErrors();
    });

    it('hasta 10 vistas compartidas por empresa, y las privadas no cuentan', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        foreach (range(1, 10) as $i) {
            makeView($agent, ['name' => "Compartida {$i}", 'shared' => true]);
        }

        $this->actingAs($agent)->post('/tickets/views', ['name' => 'Otra compartida', 'status' => ['open'], 'shared' => true])->assertSessionHasErrors('name');
        $this->actingAs($agent)->post('/tickets/views', ['name' => 'Privada', 'status' => ['open']])->assertSessionHasNoErrors();
    });

    it('los topes de una empresa no afectan a otra', function (): void {
        $full = ticketTenant();
        foreach (range(1, 10) as $i) {
            makeView(ticketStaff($full), ['name' => "Compartida {$i}", 'shared' => true]);
        }
        $tenant = ticketTenant();

        $this->actingAs(ticketStaff($tenant))->post('/tickets/views', ['name' => 'Mi compartida', 'status' => ['open'], 'shared' => true])->assertSessionHasNoErrors();
    });
});

describe('usar una vista', function (): void {
    it('abre la lista con los filtros guardados', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        titled($tenant, 'Abierto');
        titled($tenant, 'Cerrado', ['status' => TicketStatus::Closed]);
        $view = makeView($agent, ['name' => 'Solo cerrados', 'filters' => ['status' => ['closed']]]);

        $response = $this->actingAs($agent)->get("/tickets?saved={$view->id}");

        expect(inboxSubjects($response))->toBe(['Cerrado'])
            ->and(inboxProp($response, 'filters'))->toBe(['status' => ['closed'], 'saved' => $view->id])
            ->and(inboxProp($response, 'total'))->toBe(1);
    });

    it('una búsqueda y un orden pedidos se aplican por encima de la vista', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        titled($tenant, 'Viejo', ['created_at' => '2026-10-01 10:00:00']);
        titled($tenant, 'Nuevo', ['created_at' => '2026-10-20 10:00:00']);
        $view = makeView($agent, ['filters' => ['status' => ['open']]]);

        $response = $this->actingAs($agent)->get("/tickets?saved={$view->id}&sort=oldest");

        expect(inboxSubjects($response))->toBe(['Viejo', 'Nuevo']);
    });

    it('los tramos siguientes usan los filtros de la vista', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        foreach (range(1, 31) as $i) {
            titled($tenant, "Cerrado {$i}", ['status' => TicketStatus::Closed, 'last_message_at' => now()->subMinutes(100 - $i)]);
            titled($tenant, "Abierto {$i}", ['last_message_at' => now()->subMinutes(100 - $i)]);
        }
        $view = makeView($agent, ['filters' => ['status' => ['closed']]]);
        $first = $this->actingAs($agent)->get("/tickets?saved={$view->id}");

        $second = $this->actingAs($agent)->getJson("/tickets/page?saved={$view->id}&cursor=".urlencode(inboxProp($first, 'next_cursor')));

        expect($second->json('data'))->toHaveCount(1)->and($second->json('data.0.subject'))->toBe('Cerrado 1');
    });

    it('cada vista trae el número de tickets que mostraría', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        titled($tenant, 'Abierto 1');
        titled($tenant, 'Abierto 2');
        titled($tenant, 'Cerrado', ['status' => TicketStatus::Closed]);
        makeView($agent, ['name' => 'Abiertos', 'filters' => ['status' => ['open']]]);
        makeView($agent, ['name' => 'Cerrados', 'filters' => ['status' => ['closed']]]);

        $views = inboxProp($this->actingAs($agent)->get('/tickets'), 'views');

        expect(array_column($views, 'count', 'name'))->toBe(['Abiertos' => 2, 'Cerrados' => 1]);
    });

    it('«Asignados a mí» dentro de una vista compartida cuenta lo de quien la mira', function (): void {
        $tenant = ticketTenant();
        $owner = ticketStaff($tenant);
        $other = ticketStaff($tenant);
        titled($tenant, 'Del dueño', ['assigned_to' => $owner->id]);
        titled($tenant, 'Del otro 1', ['assigned_to' => $other->id]);
        titled($tenant, 'Del otro 2', ['assigned_to' => $other->id]);
        makeView($owner, ['name' => 'Mis cosas', 'shared' => true, 'filters' => ['scope' => 'mine']]);

        expect(inboxProp($this->actingAs($owner)->get('/tickets'), 'views.0.count'))->toBe(1)
            ->and(inboxProp($this->actingAs($other)->get('/tickets'), 'views.0.count'))->toBe(2);
    });

    it('una vista cuyo filtro quedó obsoleto (por ejemplo una etiqueta borrada) simplemente no encuentra nada', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        titled($tenant, 'Algo');
        $view = makeView($agent, ['filters' => ['tag' => 999999]]);

        $response = $this->actingAs($agent)->get("/tickets?saved={$view->id}");

        expect(inboxRows($response))->toBe([])->and(inboxProp($response, 'total'))->toBe(0);
    });

    it('una vista guardada con contenido manipulado en la base no ejecuta nada raro', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        titled($tenant, 'Algo');
        $view = makeView($agent, ['filters' => ['status' => ["open' OR '1'='1"], 'sort' => 'id;--', 'agent' => '1 OR 1=1', 'scope' => ['x'], 'q' => ['y']]]);

        $this->actingAs($agent)->get("/tickets?saved={$view->id}")->assertOk();
    });
});

describe('quién ve cada vista', function (): void {
    it('ve las propias y las compartidas, y no las privadas de otra persona', function (): void {
        $tenant = ticketTenant();
        $me = ticketStaff($tenant);
        $other = ticketStaff($tenant);
        makeView($me, ['name' => 'Mía']);
        makeView($other, ['name' => 'Privada de otra']);
        makeView($other, ['name' => 'Compartida de otra', 'shared' => true]);

        $names = array_column(inboxProp($this->actingAs($me)->get('/tickets'), 'views'), 'name');

        expect($names)->toEqualCanonicalizing(['Mía', 'Compartida de otra']);
    });

    it('el observador ve las compartidas pero no puede guardar vistas', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        makeView($agent, ['name' => 'Compartida', 'shared' => true]);
        makeView($agent, ['name' => 'Privada']);
        $observer = ticketStaff($tenant, Role::Observer);

        $response = $this->actingAs($observer)->get('/tickets');

        expect(array_column(inboxProp($response, 'views'), 'name'))->toBe(['Compartida'])
            ->and(inboxProp($response, 'can.save_view'))->toBeFalse();
        $this->actingAs($observer)->post('/tickets/views', ['name' => 'Mía', 'status' => ['open']])->assertForbidden();
    });

    it('la vista privada de otra persona da 404, igual que una que no existe', function (): void {
        $tenant = ticketTenant();
        $owner = ticketStaff($tenant);
        $view = makeView($owner, ['name' => 'Privada']);
        $intruder = ticketStaff($tenant);

        $this->actingAs($intruder)->get("/tickets?saved={$view->id}")->assertNotFound();
        $this->actingAs($intruder)->getJson("/tickets/page?saved={$view->id}")->assertNotFound();
        $this->actingAs($intruder)->get('/tickets?saved=999999')->assertNotFound();
    });

    it('una vista de otra empresa da 404 aunque sea compartida', function (): void {
        $view = makeView(ticketStaff(ticketTenant()), ['shared' => true]);
        $intruder = ticketStaff(ticketTenant());

        $this->actingAs($intruder)->get("/tickets?saved={$view->id}")->assertNotFound();
        expect(array_column(inboxProp($this->actingAs($intruder)->get('/tickets'), 'views'), 'name'))->toBe([]);
    });
});

describe('eliminar una vista', function (): void {
    it('quien la creó la elimina y la lista vuelve a la bandeja completa', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $view = makeView($agent);

        $this->actingAs($agent)->delete("/tickets/views/{$view->id}")->assertRedirect(route('tickets'))->assertSessionHas('status', 'Vista eliminada.');

        expect(viewsOf($tenant))->toHaveCount(0);
    });

    it('eliminar una vista no borra tickets', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        titled($tenant, 'Sigue aquí');
        $view = makeView($agent);

        $this->actingAs($agent)->delete("/tickets/views/{$view->id}");

        expect(inboxSubjects($this->actingAs($agent)->get('/tickets')))->toBe(['Sigue aquí']);
    });

    it('un administrador elimina las vistas compartidas de otras personas, pero no las privadas', function (): void {
        $tenant = ticketTenant();
        $admin = ticketStaff($tenant, Role::Admin);
        $agent = ticketStaff($tenant);
        $shared = makeView($agent, ['name' => 'Compartida', 'shared' => true]);
        $private = makeView($agent, ['name' => 'Privada']);

        $this->actingAs($admin)->delete("/tickets/views/{$shared->id}")->assertRedirect();
        $this->actingAs($admin)->delete("/tickets/views/{$private->id}")->assertNotFound();

        expect(viewsOf($tenant)->pluck('name')->all())->toBe(['Privada']);
    });

    it('un agente no elimina la vista compartida de otra persona', function (): void {
        $tenant = ticketTenant();
        $owner = ticketStaff($tenant);
        $view = makeView($owner, ['shared' => true]);

        $this->actingAs(ticketStaff($tenant))->delete("/tickets/views/{$view->id}")->assertForbidden();

        expect(viewsOf($tenant))->toHaveCount(1);
    });

    it('un observador no elimina ninguna', function (): void {
        $tenant = ticketTenant();
        $view = makeView(ticketStaff($tenant), ['shared' => true]);

        $this->actingAs(ticketStaff($tenant, Role::Observer))->delete("/tickets/views/{$view->id}")->assertForbidden();

        expect(viewsOf($tenant))->toHaveCount(1);
    });

    it('otra empresa recibe 404 y no borra nada', function (): void {
        $tenant = ticketTenant();
        $view = makeView(ticketStaff($tenant), ['shared' => true]);

        $this->assertOtherTenantGets404(ticketStaff(ticketTenant(), Role::Admin), 'DELETE', "/tickets/views/{$view->id}");

        expect(viewsOf($tenant))->toHaveCount(1);
    });

    it('cada vista dice si quien mira puede eliminarla', function (): void {
        $tenant = ticketTenant();
        $owner = ticketStaff($tenant);
        $admin = ticketStaff($tenant, Role::Admin);
        $colleague = ticketStaff($tenant);
        makeView($owner, ['name' => 'Mía', 'shared' => true]);

        expect(inboxProp($this->actingAs($owner)->get('/tickets'), 'views.0.deletable'))->toBeTrue()
            ->and(inboxProp($this->actingAs($admin)->get('/tickets'), 'views.0.deletable'))->toBeTrue()
            ->and(inboxProp($this->actingAs($colleague)->get('/tickets'), 'views.0.deletable'))->toBeFalse();
    });

    it('el identificador debe ser un número', function (): void {
        $this->actingAs(ticketStaff(ticketTenant()))->delete('/tickets/views/abc')->assertNotFound();
    });
});

describe('acceso', function (): void {
    it('un visitante, un desactivado y el personal de la plataforma no guardan ni eliminan', function (): void {
        $tenant = ticketTenant();
        $view = makeView(ticketStaff($tenant));
        $staff = User::factory()->create(['is_platform_admin' => true]);
        $staff->forceFill(['two_factor_secret' => encrypt('S'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()])->save();

        $this->post('/tickets/views', ['name' => 'X', 'status' => ['open']])->assertRedirect('/login');
        $this->delete("/tickets/views/{$view->id}")->assertRedirect('/login');
        $this->actingAs(ticketStaff($tenant, Role::Agent, disabled: true))->post('/tickets/views', ['name' => 'X', 'status' => ['open']])->assertRedirect(route('login'));
        $this->actingAs($staff)->post('/tickets/views', ['name' => 'X', 'status' => ['open']])->assertForbidden();
    });

    it('un administrador sin 2FA pasa primero por la pantalla de seguridad', function (): void {
        $tenant = ticketTenant();
        $admin = User::factory()->forTenant($tenant, Role::Admin)->create();

        $this->actingAs($admin)->post('/tickets/views', ['name' => 'X', 'status' => ['open']])->assertRedirect(route('security'));
    });

    it('limita guardar y eliminar a 120 por minuto por persona', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);

        foreach (range(1, 120) as $ignored) {
            $this->actingAs($agent)->delete('/tickets/views/999999')->assertNotFound();
        }

        $this->actingAs($agent)->delete('/tickets/views/999999')->assertTooManyRequests();
    });
});
