<?php

declare(strict_types=1);

require_once __DIR__.'/../Widget/Helpers.php';

use App\Api\ApiKeys;
use App\Api\ApiPermission;
use App\Enums\Role;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

function keysOf(Tenant $tenant): array
{
    return app(TenantContext::class)->run($tenant, fn () => ApiKey::query()->orderBy('id')->get()->all());
}

/** Crea una llave por la pantalla y devuelve la llave completa que se entregó una sola vez. */
function createKeyVia(object $test, User $admin, array $payload = []): string
{
    $test->actingAs($admin)->post('/settings/api-keys', ['name' => 'prod-backend-01', 'permission' => 'read', ...$payload])->assertSessionHasNoErrors();
    $page = $test->actingAs($admin)->get('/settings/api-keys')->assertOk()->viewData('page')['props'];

    return $page['created']['value'];
}

describe('crear una llave', function (): void {
    it('el administrador la crea y la ve completa una sola vez; en la base solo queda su huella y su prefijo', function (): void {
        $tenant = widgetTenant();
        $admin = widgetAdmin($tenant);

        $this->actingAs($admin)->post('/settings/api-keys', ['name' => 'prod-backend-01', 'permission' => 'write', 'expires_in_days' => 90])->assertSessionHasNoErrors();

        $first = $this->actingAs($admin)->get('/settings/api-keys')->assertOk()->viewData('page')['props'];
        $plain = $first['created']['value'];
        expect($plain)->toMatch('/\Atk_'.$tenant->id.'_[A-Za-z0-9]{40}\z/')->and($first['created']['name'])->toBe('prod-backend-01');

        $key = keysOf($tenant)[0];
        expect($key->key_hash)->toBe(hash('sha256', $plain))
            ->and($key->prefix)->toBe(substr($plain, 0, strlen('tk_'.$tenant->id.'_') + 6))
            ->and($key->permission)->toBe(ApiPermission::Write)
            ->and($key->expires_at?->isFuture())->toBeTrue()
            ->and($key->created_by)->toBe($admin->id);

        // La segunda vez que se abre la pantalla la llave ya no existe.
        $second = $this->actingAs($admin)->get('/settings/api-keys')->viewData('page')['props'];
        expect($second['created'])->toBeNull()->and(json_encode($second))->not->toContain($plain);
        expect(json_encode(DB::table('api_keys')->get()))->not->toContain(substr($plain, strlen('tk_'.$tenant->id.'_') + 6));
    });

    it('la lista solo trae el prefijo, el acceso, las fechas y el estado', function (): void {
        $tenant = widgetTenant();
        $admin = widgetAdmin($tenant);
        createKeyVia($this, $admin);

        $row = $this->actingAs($admin)->get('/settings/api-keys')->viewData('page')['props']['keys'][0];

        expect(array_keys($row))->toBe(['id', 'name', 'prefix', 'permission', 'created_at', 'last_used_at', 'expires_at', 'status'])
            ->and($row['status'])->toBe('active')->and($row['last_used_at'])->toBeNull();
    });

    it('cada llave es distinta y de alta entropía', function (): void {
        $admin = widgetAdmin();

        $a = createKeyVia($this, $admin, ['name' => 'a']);
        $b = createKeyVia($this, $admin, ['name' => 'b']);

        expect($a)->not->toBe($b)->and(strlen($a))->toBeGreaterThanOrEqual(45);
    });

    it('valida el nombre, el acceso y la caducidad con listas cerradas', function (array $payload, string $field): void {
        $admin = widgetAdmin();

        $this->actingAs($admin)->post('/settings/api-keys', ['name' => 'x', 'permission' => 'read', ...$payload])->assertSessionHasErrors($field);

        expect(keysOf($admin->tenant()->firstOrFail()))->toBe([]);
    })->with([
        'sin nombre' => [['name' => ''], 'name'],
        'solo espacios' => [['name' => '   '], 'name'],
        'nombre largo' => [['name' => str_repeat('a', 61)], 'name'],
        'con control' => [['name' => "a\x07b"], 'name'],
        'acceso inventado' => [['permission' => 'root'], 'permission'],
        'sin acceso' => [['permission' => ''], 'permission'],
        'caducidad inventada' => [['expires_in_days' => 7], 'expires_in_days'],
        'caducidad texto' => [['expires_in_days' => 'siempre'], 'expires_in_days'],
    ]);

    it('lo que el cliente intente fijar (empresa, huella, creador) se ignora', function (): void {
        $mine = widgetTenant();
        $theirs = widgetTenant();
        $admin = widgetAdmin($mine);

        $this->actingAs($admin)->post('/settings/api-keys', ['name' => 'a', 'permission' => 'read', 'tenant_id' => $theirs->id, 'key_hash' => str_repeat('a', 64), 'created_by' => 999999])->assertSessionHasNoErrors();

        expect(keysOf($theirs))->toBe([])->and(keysOf($mine)[0]->key_hash)->not->toBe(str_repeat('a', 64))->and(keysOf($mine)[0]->created_by)->toBe($admin->id);
    });

    it('tiene un máximo de llaves activas por empresa; revocar libera un lugar', function (): void {
        $tenant = widgetTenant();
        $admin = widgetAdmin($tenant);
        foreach (range(1, 10) as $number) {
            $this->actingAs($admin)->post('/settings/api-keys', ['name' => "llave-{$number}", 'permission' => 'read'])->assertSessionHasNoErrors();
        }

        $this->actingAs($admin)->post('/settings/api-keys', ['name' => 'otra', 'permission' => 'read'])
            ->assertSessionHasErrors(['name' => 'Ya tienes 10 llaves activas, el máximo por empresa. Revoca una para crear otra.']);

        $this->actingAs($admin)->delete('/settings/api-keys/'.keysOf($tenant)[0]->id)->assertRedirect();
        $this->actingAs($admin)->post('/settings/api-keys', ['name' => 'otra', 'permission' => 'read'])->assertSessionHasNoErrors();
        expect(keysOf($tenant))->toHaveCount(11);
    });

    it('lo hacen solo los administradores activos de la empresa', function (): void {
        $tenant = widgetTenant();

        foreach ([Role::Agent, Role::Observer] as $role) {
            $user = ticketStaff($tenant, $role);
            $this->actingAs($user)->get('/settings/api-keys')->assertForbidden();
            $this->actingAs($user)->post('/settings/api-keys', ['name' => 'x', 'permission' => 'read'])->assertForbidden();
        }
        $this->actingAs(ticketStaff($tenant, Role::Admin, disabled: true))->get('/settings/api-keys')->assertRedirect('/login');
        auth()->logout();
        $this->get('/settings/api-keys')->assertRedirect('/login');
        expect(keysOf($tenant))->toBe([]);
    });

    it('queda auditado sin la llave', function (): void {
        $tenant = widgetTenant();
        $admin = widgetAdmin($tenant);
        $plain = createKeyVia($this, $admin);

        $log = AuditLog::query()->where('event', 'api_key.created')->sole();

        expect($log->user_id)->toBe($admin->id)->and($log->context)->toEqual(['credential_id' => keysOf($tenant)[0]->id, 'permission' => 'read'])
            ->and(json_encode(DB::table('audit_logs')->get()))->not->toContain($plain);
    });

    it('la barra lateral ofrece «Llaves de API» solo a quien puede abrirlas', function (): void {
        $tenant = widgetTenant();

        $admin = $this->actingAs(widgetAdmin($tenant))->get('/account')->viewData('page')['props']['auth']['can'];
        $agent = $this->actingAs(ticketStaff($tenant, Role::Agent))->get('/account')->viewData('page')['props']['auth']['can'];

        expect($admin['api_keys'])->toBeTrue()->and($agent['api_keys'])->toBeFalse();
    });
});

describe('rotar y revocar', function (): void {
    it('rotar crea otra llave con los mismos ajustes y la anterior sigue activa hasta revocarla', function (): void {
        $tenant = widgetTenant();
        $admin = widgetAdmin($tenant);
        $old = createKeyVia($this, $admin, ['name' => 'integracion', 'permission' => 'admin', 'expires_in_days' => 30]);
        $oldKey = keysOf($tenant)[0];

        $this->actingAs($admin)->post("/settings/api-keys/{$oldKey->id}/rotate")->assertRedirect();
        $new = $this->actingAs($admin)->get('/settings/api-keys')->viewData('page')['props']['created']['value'];

        $keys = keysOf($tenant);
        expect($keys)->toHaveCount(2)->and($new)->not->toBe($old)
            ->and($keys[1]->name)->toBe('integracion')->and($keys[1]->permission)->toBe(ApiPermission::Admin)
            ->and($keys[1]->expires_at?->isFuture())->toBeTrue()
            ->and($keys[0]->isUsable())->toBeTrue()->and($keys[1]->isUsable())->toBeTrue();
        expect(AuditLog::query()->where('event', 'api_key.rotated')->sole()->context)->toEqual(['credential_id' => $oldKey->id, 'new_credential_id' => $keys[1]->id]);
    });

    it('revocar deja la llave sin efecto de inmediato y la lista la marca revocada', function (): void {
        $tenant = widgetTenant();
        $admin = widgetAdmin($tenant);
        createKeyVia($this, $admin);
        $key = keysOf($tenant)[0];

        $this->actingAs($admin)->delete("/settings/api-keys/{$key->id}")->assertRedirect()->assertSessionHas('status', 'Llave «prod-backend-01» revocada.');

        expect(keysOf($tenant)[0]->isUsable())->toBeFalse();
        expect($this->actingAs($admin)->get('/settings/api-keys')->viewData('page')['props']['keys'][0]['status'])->toBe('revoked');
        expect(AuditLog::query()->where('event', 'api_key.revoked')->count())->toBe(1);
        $this->actingAs($admin)->delete("/settings/api-keys/{$key->id}")->assertRedirect();
        expect(AuditLog::query()->where('event', 'api_key.revoked')->count())->toBe(1);
    });

    it('una llave caducada figura como caducada', function (): void {
        $tenant = widgetTenant();
        $admin = widgetAdmin($tenant);
        createKeyVia($this, $admin, ['expires_in_days' => 30]);
        $this->travel(31)->days();

        $row = $this->actingAs($admin)->get('/settings/api-keys')->viewData('page')['props']['keys'][0];

        expect($row['status'])->toBe('expired');
    });

    it('una llave de otra empresa da 404 al rotarla o revocarla y no se toca', function (): void {
        $mine = widgetTenant();
        $theirs = widgetTenant();
        createKeyVia($this, widgetAdmin($theirs));
        $theirKey = keysOf($theirs)[0];

        $this->actingAs(widgetAdmin($mine))->post("/settings/api-keys/{$theirKey->id}/rotate")->assertNotFound();
        $this->actingAs(widgetAdmin($mine))->delete("/settings/api-keys/{$theirKey->id}")->assertNotFound();

        expect(keysOf($theirs))->toHaveCount(1)->and(keysOf($theirs)[0]->isUsable())->toBeTrue();
    });

    it('agentes y observadores no rotan ni revocan', function (): void {
        $tenant = widgetTenant();
        createKeyVia($this, widgetAdmin($tenant));
        $key = keysOf($tenant)[0];

        foreach ([Role::Agent, Role::Observer] as $role) {
            $this->actingAs(ticketStaff($tenant, $role))->post("/settings/api-keys/{$key->id}/rotate")->assertForbidden();
            $this->actingAs(ticketStaff($tenant, $role))->delete("/settings/api-keys/{$key->id}")->assertForbidden();
        }

        expect(keysOf($tenant))->toHaveCount(1);
    });

    it('un identificador que no es un número no llega a la consulta', function (): void {
        $admin = widgetAdmin();

        $this->actingAs($admin)->delete('/settings/api-keys/abc')->assertNotFound();
    });

    it('la pantalla tiene un tope de peticiones por cuenta', function (): void {
        $admin = widgetAdmin();

        foreach (range(1, 20) as $_) {
            $this->actingAs($admin)->post('/settings/api-keys', ['name' => '', 'permission' => 'read']);
        }

        $this->actingAs($admin)->post('/settings/api-keys', ['name' => 'x', 'permission' => 'read'])->assertStatus(429);
    });
});

describe('el formato de la llave', function (): void {
    it('solo se reconoce la forma exacta', function (): void {
        $secret = str_repeat('a', 40);

        expect(ApiKeys::parse("tk_7_{$secret}"))->toBe(['tenant_id' => 7, 'hash' => hash('sha256', "tk_7_{$secret}")])
            ->and(ApiKeys::parse(null))->toBeNull()
            ->and(ApiKeys::parse(''))->toBeNull()
            ->and(ApiKeys::parse("tk_0_{$secret}"))->toBeNull()
            ->and(ApiKeys::parse("tk_07_{$secret}"))->toBeNull()
            ->and(ApiKeys::parse("tk_7_{$secret}x"))->toBeNull()
            ->and(ApiKeys::parse('tk_7_'.substr($secret, 1)))->toBeNull()
            ->and(ApiKeys::parse("tk_7_{$secret}\n"))->toBeNull()
            ->and(ApiKeys::parse("TK_7_{$secret}"))->toBeNull()
            ->and(ApiKeys::parse("tk_7_'{$secret}"))->toBeNull();
    });

    it('los permisos se incluyen unos a otros', function (): void {
        expect(ApiPermission::Admin->allows(ApiPermission::Write))->toBeTrue()
            ->and(ApiPermission::Write->allows(ApiPermission::Read))->toBeTrue()
            ->and(ApiPermission::Read->allows(ApiPermission::Write))->toBeFalse()
            ->and(ApiPermission::Write->allows(ApiPermission::Admin))->toBeFalse();
    });
});
