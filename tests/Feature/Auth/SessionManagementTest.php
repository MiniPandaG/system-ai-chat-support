<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\DeviceDescriber;
use App\Services\UserSessions;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

const CHROME_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

/**
 * Sesión con la contraseña confirmada hace poco, que es lo que exigen las rutas de Seguridad.
 *
 * @return array<string, int>
 */
function confirmedRecently(): array
{
    return ['auth.password_confirmed_at' => time()];
}

/**
 * La petición llega con una cookie de sesión de identificador conocido, para saber cuál es «la actual».
 */
function withCurrentSession(TestCase $test, User $user, string $id): TestCase
{
    DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => time()]);

    return $test->actingAs($user)
        ->withSession(confirmedRecently())
        ->withCookie(config()->string('session.cookie'), $id);
}

function openSession(User $user, ?string $agent = CHROME_WINDOWS, ?int $lastActivity = null): string
{
    $id = Str::random(40);

    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => '203.0.113.9',
        'user_agent' => $agent,
        'payload' => 'x',
        'last_activity' => $lastActivity ?? time(),
    ]);

    return $id;
}

it('la pantalla de seguridad lista las sesiones de la cuenta sin exponer su identificador', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $id = openSession($user);
    openSession($other);

    $this->actingAs($user)
        ->withSession(confirmedRecently())
        ->get('/settings/security')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($id): void {
            $page->component('settings/Security')
                ->has('sessions', 1)
                ->where('sessions.0.device', 'Chrome en Windows')
                ->where('sessions.0.ip', '203.0.113.9')
                ->where('sessions.0.handle', UserSessions::handle($id))
                ->missing('sessions.0.id');
        });

    expect(UserSessions::handle($id))->toMatch('/^[a-f0-9]{64}$/')->not->toBe($id);
});

it('no lista las sesiones que ya caducaron por inactividad', function (): void {
    $user = User::factory()->create();
    openSession($user, CHROME_WINDOWS, time() - (config()->integer('session.lifetime') + 5) * 60);
    $fresh = openSession($user);

    $listed = app(UserSessions::class)->list($user, 'otra-sesion');

    expect($listed)->toHaveCount(1)
        ->and($listed[0]['handle'])->toBe(UserSessions::handle($fresh));
});

it('marca la sesión actual y la pone primero', function (): void {
    $user = User::factory()->create();
    openSession($user, CHROME_WINDOWS, time() - 120);
    $current = openSession($user, CHROME_WINDOWS, time() - 600);

    $listed = app(UserSessions::class)->list($user, $current);

    expect($listed[0]['current'])->toBeTrue()
        ->and($listed[0]['handle'])->toBe(UserSessions::handle($current))
        ->and($listed[1]['current'])->toBeFalse()
        ->and($listed[1]['last_activity_label'])->toStartWith('hace');
});

it('cierra una sesión ajena de la cuenta y lo audita sin la IP', function (): void {
    $user = User::factory()->create();
    $target = openSession($user);
    $kept = openSession($user);

    $this->actingAs($user)
        ->withSession(confirmedRecently())
        ->delete('/settings/sessions/'.UserSessions::handle($target))
        ->assertSessionHas('status', 'Cerramos esa sesión.');

    expect(DB::table('sessions')->where('id', $target)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', $kept)->exists())->toBeTrue();

    $log = AuditLog::query()->where('event', AuditEvent::SessionRevoked->value)->where('user_id', $user->id)->first();

    expect($log)->not->toBeNull()
        ->and(json_encode($log?->context))->not->toContain('203.0.113.9');
});

it('una cuenta no puede cerrar las sesiones de otra: recibe 404 y la sesión sigue abierta', function (): void {
    $user = User::factory()->create();
    $victim = User::factory()->create();
    $victimSession = openSession($victim);

    $this->actingAs($user)
        ->withSession(confirmedRecently())
        ->delete('/settings/sessions/'.UserSessions::handle($victimSession))
        ->assertNotFound();

    expect(DB::table('sessions')->where('id', $victimSession)->exists())->toBeTrue();
});

it('un código inexistente o mal formado da 404', function (string $handle): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(confirmedRecently())
        ->delete('/settings/sessions/'.$handle)
        ->assertNotFound();
})->with([
    'bien formado pero inexistente' => [str_repeat('a', 64)],
    'demasiado corto' => ['abc123'],
    'con caracteres raros' => ['..%2F..%2Fetc'],
]);

it('el código de la sesión actual no sirve para cerrarla por esta ruta', function (): void {
    $user = User::factory()->create();

    $current = Str::random(40);

    withCurrentSession($this, $user, $current)
        ->delete('/settings/sessions/'.UserSessions::handle($current))
        ->assertNotFound();

    expect(DB::table('sessions')->where('id', $current)->exists())->toBeTrue();
});

it('cerrar las demás deja la actual y no toca las de otras cuentas', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $current = Str::random(40);

    withCurrentSession($this, $user, $current);
    openSession($user);
    openSession($user);
    $foreign = openSession($other);

    $this->delete('/settings/sessions')->assertSessionHas('status', 'Cerramos todas las demás sesiones.');

    expect(DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all())->toBe([$current])
        ->and(DB::table('sessions')->where('id', $foreign)->exists())->toBeTrue();

    $log = AuditLog::query()->where('event', AuditEvent::SessionsRevoked->value)->where('user_id', $user->id)->first();

    expect($log?->context)->toBe(['sessions_closed' => 2, 'includes_current' => false]);
});

it('cerrar todas cierra también la actual y lleva al inicio de sesión', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    withCurrentSession($this, $user, Str::random(40));
    openSession($user);
    $foreign = openSession($other);

    $this->delete('/settings/sessions/all')
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Cerramos todas tus sesiones. Inicia sesión de nuevo para continuar.');

    $this->assertGuest();

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('id', $foreign)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('event', AuditEvent::SessionsRevoked->value)->where('user_id', $user->id)->exists())->toBeTrue();
});

it('las rutas de sesiones exigen sesión iniciada y contraseña confirmada', function (): void {
    $user = User::factory()->create();
    $target = openSession($user);

    $this->delete('/settings/sessions')->assertRedirect('/login');
    $this->delete('/settings/sessions/all')->assertRedirect('/login');
    $this->delete('/settings/sessions/'.UserSessions::handle($target))->assertRedirect('/login');

    $this->actingAs($user);
    $this->delete('/settings/sessions')->assertRedirect(route('password.confirm'));
    $this->delete('/settings/sessions/all')->assertRedirect(route('password.confirm'));
    $this->delete('/settings/sessions/'.UserSessions::handle($target))->assertRedirect(route('password.confirm'));

    expect(DB::table('sessions')->where('id', $target)->exists())->toBeTrue();
});

it('describe el dispositivo solo con nombres de una lista cerrada', function (?string $agent, string $expected): void {
    expect(app(DeviceDescriber::class)->describe($agent))->toBe($expected);
})->with([
    'Chrome en Windows' => [CHROME_WINDOWS, 'Chrome en Windows'],
    'Edge en Windows' => ['Mozilla/5.0 (Windows NT 10.0) Chrome/130.0 Safari/537.36 Edg/130.0', 'Edge en Windows'],
    'Firefox en Linux' => ['Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0', 'Firefox en Linux'],
    'Safari en iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Safari/604.1', 'Safari en iOS'],
    'Chrome en Android' => ['Mozilla/5.0 (Linux; Android 14) Chrome/130.0 Mobile Safari/537.36', 'Chrome en Android'],
    'sin user agent' => [null, 'Dispositivo desconocido'],
    'texto ajeno' => ['<script>alert(1)</script>', 'Dispositivo desconocido'],
    'intento de inyección junto a un navegador real' => ['<img src=x onerror=alert(1)> Firefox/130.0', 'Firefox en sistema desconocido'],
]);
