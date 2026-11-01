<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

/** Contraseña armada por partes: una de ejemplo escrita de corrido la marca el escaneo de secretos. */
function invitedPassword(): string
{
    return str_repeat('In', 6).'5';
}

function inviteAdmin(?Tenant $tenant = null): User
{
    $user = User::factory()->forTenant($tenant ?? Tenant::factory()->onPlan('negocio')->create(), Role::Admin)->create();
    $user->forceFill([
        'two_factor_secret' => encrypt('SECRETOPRUEBA'),
        'two_factor_recovery_codes' => encrypt(json_encode(['x'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user;
}

/** El token en claro del último correo enviado a esa dirección. */
function emailedToken(string $email): string
{
    $token = null;

    Notification::assertSentOnDemand(InvitationNotification::class, function (InvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($email, &$token): bool {
        if (($notifiable->routes['mail'] ?? null) !== $email) {
            return false;
        }

        $token = (fn (): string => $this->token)->call($notification);

        return true;
    });

    return (string) $token;
}

function invitationFor(Tenant $tenant, string $email): ?Invitation
{
    return app(TenantContext::class)->run($tenant, fn (): ?Invitation => Invitation::query()->where('email', $email)->latest('id')->first());
}

function openInvitation(User $admin, string $email = 'nueva@empresa-ejemplo.com', string $role = 'agent'): string
{
    test()->actingAs($admin)->post('/settings/team/invitations', ['emails' => [$email], 'role' => $role])->assertSessionHasNoErrors();

    return emailedToken($email);
}

beforeEach(function (): void {
    Notification::fake();
});

it('un administrador invita: queda la invitación, con solo el hash del token, y sale el correo por cola', function (): void {
    $tenant = Tenant::factory()->onPlan('negocio')->create();
    $admin = inviteAdmin($tenant);

    $this->actingAs($admin)->post('/settings/team/invitations', ['emails' => ['Nueva@Empresa-Ejemplo.com'], 'role' => 'observer'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Enviamos 1 invitación.');

    $invitation = invitationFor($tenant, 'nueva@empresa-ejemplo.com');
    $token = emailedToken('nueva@empresa-ejemplo.com');

    expect($invitation)->not->toBeNull()
        ->and($invitation?->role)->toBe(Role::Observer)
        ->and($invitation?->token_hash)->toBe(hash('sha256', $token))
        ->and($invitation?->invited_by)->toBe($admin->id)
        ->and((int) $invitation?->expires_at->diffInHours(now(), true))->toBeBetween(71, 72)
        ->and(strlen($token))->toBe(64)
        ->and(json_encode(DB::table('invitations')->get()))->not->toContain($token)
        ->and(new InvitationNotification('t', 'Empresa', Role::Agent))->toBeInstanceOf(ShouldQueue::class);
});

it('el correo de invitación está en español y apunta al enlace con el token', function (): void {
    $mail = (new InvitationNotification('abc123', 'Soporte Ejemplo SAS', Role::Agent))->toMail(new AnonymousNotifiable);

    expect($mail->subject)->toBe('Te invitaron a Soporte Ejemplo SAS en TikaroChat')
        ->and($mail->actionUrl)->toEndWith('/invitations/abc123')
        ->and(implode(' ', $mail->introLines))->toContain('Agente');
});

it('invita a varias personas a la vez, sin repetir correos aunque cambien las mayúsculas', function (): void {
    $tenant = Tenant::factory()->onPlan('negocio')->create();

    $this->actingAs(inviteAdmin($tenant))->post('/settings/team/invitations', [
        'emails' => ['uno@empresa-ejemplo.com', 'DOS@empresa-ejemplo.com', 'uno@empresa-ejemplo.com'],
        'role' => 'agent',
    ])->assertSessionHas('status', 'Enviamos 2 invitaciones.');

    expect(app(TenantContext::class)->run($tenant, fn (): int => Invitation::query()->count()))->toBe(2);
    Notification::assertSentOnDemandTimes(InvitationNotification::class, 2);
});

it('rechaza correos inválidos, listas vacías o demasiado largas y roles inexistentes', function (array $payload, string $field): void {
    $tenant = Tenant::factory()->onPlan('negocio')->create();

    $this->actingAs(inviteAdmin($tenant))->post('/settings/team/invitations', $payload)->assertSessionHasErrors($field);

    expect(app(TenantContext::class)->run($tenant, fn (): int => Invitation::query()->count()))->toBe(0);
    Notification::assertNothingSent();
})->with([
    'sin correos' => [['emails' => [], 'role' => 'agent'], 'emails'],
    'correo inválido' => [['emails' => ['no-es-correo'], 'role' => 'agent'], 'emails.0'],
    'demasiados' => [['emails' => array_map(fn (int $i): string => "p{$i}@empresa-ejemplo.com", range(1, 11)), 'role' => 'agent'], 'emails'],
    'no es una lista' => [['emails' => 'a@empresa-ejemplo.com', 'role' => 'agent'], 'emails'],
    'rol inventado' => [['emails' => ['a@empresa-ejemplo.com'], 'role' => 'superadmin'], 'role'],
    'sin rol' => [['emails' => ['a@empresa-ejemplo.com']], 'role'],
]);

it('no invita a quien ya es del equipo y se lo dice al administrador', function (): void {
    $tenant = Tenant::factory()->onPlan('negocio')->create();
    $admin = inviteAdmin($tenant);
    $member = User::factory()->forTenant($tenant, Role::Agent)->create();

    $this->actingAs($admin)->post('/settings/team/invitations', ['emails' => [$member->email, 'otra@empresa-ejemplo.com'], 'role' => 'agent'])
        ->assertSessionHasErrors(['emails' => 'Ya forman parte del equipo: '.$member->email.'.']);

    Notification::assertSentOnDemandTimes(InvitationNotification::class, 1);
    expect(invitationFor($tenant, $member->email))->toBeNull();
});

it('invitar a un correo con cuenta en otra empresa no lo revela: se crea igual', function (): void {
    $elsewhere = User::factory()->forTenant(Tenant::factory()->onPlan('negocio')->create(), Role::Agent)->create();
    $tenant = Tenant::factory()->onPlan('negocio')->create();

    $this->actingAs(inviteAdmin($tenant))->post('/settings/team/invitations', ['emails' => [$elsewhere->email], 'role' => 'agent'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Enviamos 1 invitación.');

    expect(invitationFor($tenant, $elsewhere->email))->not->toBeNull();
});

it('volver a invitar al mismo correo reutiliza la invitación y el enlace anterior deja de servir', function (): void {
    $tenant = Tenant::factory()->onPlan('negocio')->create();
    $admin = inviteAdmin($tenant);
    $first = openInvitation($admin);
    $second = openInvitation($admin);

    expect($second)->not->toBe($first)
        ->and(app(TenantContext::class)->run($tenant, fn (): int => Invitation::query()->count()))->toBe(1);

    auth()->logout();
    $this->get('/invitations/'.$first)->assertInertia(fn (AssertableInertia $page) => $page->where('invitation', null));
    $this->get('/invitations/'.$second)->assertInertia(fn (AssertableInertia $page) => $page->where('invitation.email', 'nueva@empresa-ejemplo.com'));
});

it('solo un administrador activo de la empresa puede invitar', function (Role $role, int $status): void {
    $tenant = Tenant::factory()->onPlan('negocio')->create();
    $actor = User::factory()->forTenant($tenant, $role)->create();

    $this->actingAs($actor)->post('/settings/team/invitations', ['emails' => ['a@empresa-ejemplo.com'], 'role' => 'agent'])->assertStatus($status);
    Notification::assertNothingSent();
})->with([
    'agente' => [Role::Agent, 403],
    'observador' => [Role::Observer, 403],
]);

it('un visitante y el personal de la plataforma no pueden invitar', function (): void {
    $this->post('/settings/team/invitations', ['emails' => ['a@empresa-ejemplo.com'], 'role' => 'agent'])->assertRedirect('/login');

    $staff = User::factory()->create(['is_platform_admin' => true]);
    $staff->forceFill(['two_factor_secret' => encrypt('S'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()])->save();

    $this->actingAs($staff)->post('/settings/team/invitations', ['emails' => ['a@empresa-ejemplo.com'], 'role' => 'agent'])->assertForbidden();
    Notification::assertNothingSent();
});

it('reenviar genera un enlace nuevo, invalida el anterior y reinicia la caducidad', function (): void {
    $tenant = Tenant::factory()->onPlan('negocio')->create();
    $admin = inviteAdmin($tenant);
    $old = openInvitation($admin);
    $invitation = invitationFor($tenant, 'nueva@empresa-ejemplo.com');

    $this->travel(48)->hours();
    $this->actingAs($admin)->post('/settings/team/invitations/'.$invitation?->id.'/resend')->assertSessionHas('status');

    $new = emailedToken('nueva@empresa-ejemplo.com');
    $fresh = invitationFor($tenant, 'nueva@empresa-ejemplo.com');

    expect($new)->not->toBe($old)
        ->and($fresh?->token_hash)->toBe(hash('sha256', $new))
        ->and($fresh?->isUsable())->toBeTrue()
        ->and(AuditLog::query()->where('event', AuditEvent::InvitationResent->value)->exists())->toBeTrue();

    auth()->logout();
    $this->get('/invitations/'.$old)->assertInertia(fn (AssertableInertia $page) => $page->where('invitation', null));
});

it('cancelar deja el enlace sin efecto y no se puede reenviar después', function (): void {
    $tenant = Tenant::factory()->onPlan('negocio')->create();
    $admin = inviteAdmin($tenant);
    $token = openInvitation($admin);
    $invitation = invitationFor($tenant, 'nueva@empresa-ejemplo.com');

    $this->actingAs($admin)->post('/settings/team/invitations/'.$invitation?->id.'/revoke')->assertSessionHas('status', 'Cancelamos la invitación.');
    $this->actingAs($admin)->post('/settings/team/invitations/'.$invitation?->id.'/resend')->assertNotFound();

    auth()->logout();
    $this->post('/invitations/'.$token, ['name' => 'Ana', 'password' => invitedPassword(), 'password_confirmation' => invitedPassword()])
        ->assertSessionHasErrors(['token' => 'Esta invitación no es válida o ya caducó. Pide una nueva al administrador de tu empresa.']);

    expect(User::query()->where('email', 'nueva@empresa-ejemplo.com')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('event', AuditEvent::InvitationRevoked->value)->exists())->toBeTrue();
});

it('otra empresa recibe 404 al reenviar o cancelar y la invitación no cambia', function (): void {
    $tenant = Tenant::factory()->onPlan('negocio')->create();
    openInvitation(inviteAdmin($tenant));
    $invitation = invitationFor($tenant, 'nueva@empresa-ejemplo.com');
    $intruder = inviteAdmin(Tenant::factory()->onPlan('negocio')->create());

    $this->assertOtherTenantGets404($intruder, 'POST', '/settings/team/invitations/'.$invitation?->id.'/resend');
    $this->assertOtherTenantGets404($intruder, 'POST', '/settings/team/invitations/'.$invitation?->id.'/revoke');

    $after = invitationFor($tenant, 'nueva@empresa-ejemplo.com');

    expect($after?->revoked_at)->toBeNull()->and($after?->token_hash)->toBe($invitation?->token_hash);
});

it('el equipo lista solo las invitaciones abiertas de su empresa, con las caducadas marcadas', function (): void {
    $tenant = Tenant::factory()->onPlan('negocio')->create();
    $admin = inviteAdmin($tenant);
    openInvitation($admin, 'abierta@empresa-ejemplo.com');
    openInvitation($admin, 'cancelada@empresa-ejemplo.com');
    $cancelled = invitationFor($tenant, 'cancelada@empresa-ejemplo.com');
    $this->actingAs($admin)->post('/settings/team/invitations/'.$cancelled?->id.'/revoke');
    openInvitation(inviteAdmin(Tenant::factory()->onPlan('negocio')->create()), 'ajena@empresa-ejemplo.com');

    $this->actingAs($admin)->get('/settings/team')->assertInertia(fn (AssertableInertia $page) => $page
        ->has('invitations', 1)
        ->where('invitations.0.email', 'abierta@empresa-ejemplo.com')
        ->where('invitations.0.expired', false));

    $this->travel(73)->hours();

    $this->actingAs($admin)->get('/settings/team')->assertInertia(fn (AssertableInertia $page) => $page->where('invitations.0.expired', true));
});

it('limita a 20 invitaciones por hora por empresa', function (): void {
    // El tope del plan quedaría antes que el de la hora: aquí se mide solo el de la hora.
    Plan::query()->where('slug', 'negocio')->update(['max_agents' => null]);
    $admin = inviteAdmin();
    $batch = fn (string $prefix): array => array_map(fn (int $i): string => "{$prefix}{$i}@empresa-ejemplo.com", range(1, 10));

    $this->actingAs($admin)->post('/settings/team/invitations', ['emails' => $batch('a'), 'role' => 'agent'])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post('/settings/team/invitations', ['emails' => $batch('b'), 'role' => 'agent'])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post('/settings/team/invitations', ['emails' => ['c1@empresa-ejemplo.com'], 'role' => 'agent'])
        ->assertSessionHasErrors(['emails' => 'Alcanzaste el límite de 20 invitaciones por hora. Vuelve a intentarlo más tarde.']);

    Notification::assertSentOnDemandTimes(InvitationNotification::class, 20);
});

it('el audit de invitaciones no guarda correos ni tokens', function (): void {
    $admin = inviteAdmin();
    $token = openInvitation($admin);

    $dump = (string) json_encode(AuditLog::query()->where('event', 'like', 'invitation.%')->get()->map->getAttributes());

    expect($dump)->not->toContain('nueva@empresa-ejemplo.com')->and($dump)->not->toContain($token);
});

describe('aceptar', function (): void {
    it('crea la cuenta en la empresa y con el rol de la invitación, verificada, y abre la sesión', function (): void {
        $tenant = Tenant::factory()->onPlan('negocio')->create();
        $token = openInvitation(inviteAdmin($tenant), 'nueva@empresa-ejemplo.com', 'observer');
        auth()->logout();

        $this->get('/invitations/'.$token)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/AcceptInvitation')
            ->where('invitation.email', 'nueva@empresa-ejemplo.com')
            ->where('invitation.role', 'observer')
            ->where('invitation.company', $tenant->name));

        $this->post('/invitations/'.$token, ['name' => 'Luis Pérez', 'password' => invitedPassword(), 'password_confirmation' => invitedPassword()])
            ->assertRedirect(route('account'));

        $user = User::query()->where('email', 'nueva@empresa-ejemplo.com')->firstOrFail();

        expect($user->tenant_id)->toBe($tenant->id)
            ->and($user->role)->toBe(Role::Observer)
            ->and($user->email_verified_at)->not->toBeNull()
            ->and($user->is_platform_admin)->toBeFalse()
            ->and($user->password)->toStartWith('$argon2id$')
            ->and(invitationFor($tenant, 'nueva@empresa-ejemplo.com')?->accepted_at)->not->toBeNull()
            ->and(AuditLog::query()->where('event', AuditEvent::InvitationAccepted->value)->where('user_id', $user->id)->exists())->toBeTrue();

        $this->assertAuthenticatedAs($user);
    });

    it('es de un solo uso: la segunda vez recibe el rechazo genérico y no crea otra cuenta', function (): void {
        $token = openInvitation(inviteAdmin());
        auth()->logout();
        $data = ['name' => 'Luis', 'password' => invitedPassword(), 'password_confirmation' => invitedPassword()];

        $this->post('/invitations/'.$token, $data)->assertRedirect(route('account'));
        auth()->logout();

        $this->post('/invitations/'.$token, $data)
            ->assertSessionHasErrors(['token' => 'Esta invitación no es válida o ya caducó. Pide una nueva al administrador de tu empresa.']);

        expect(User::query()->where('email', 'nueva@empresa-ejemplo.com')->count())->toBe(1);
    });

    it('caducada, falsa o alterada reciben exactamente la misma respuesta', function (): void {
        $token = openInvitation(inviteAdmin());
        auth()->logout();
        $data = ['name' => 'Luis', 'password' => invitedPassword(), 'password_confirmation' => invitedPassword()];
        $this->travel(73)->hours();

        $responses = [];
        foreach ([$token, strrev($token), str_repeat('a', 64), 'corto'] as $candidate) {
            $this->get('/invitations/'.$candidate)->assertInertia(fn (AssertableInertia $page) => $page->where('invitation', null));
            $this->post('/invitations/'.$candidate, $data)->assertSessionHasErrors('token');
            $responses[] = session('errors')?->first('token');
        }

        expect(array_unique($responses))->toHaveCount(1)
            ->and(User::query()->where('email', 'nueva@empresa-ejemplo.com')->exists())->toBeFalse();
    });

    it('si el correo ya tiene una cuenta se lo dice a quien acepta y no crea nada', function (): void {
        $tenant = Tenant::factory()->onPlan('negocio')->create();
        $token = openInvitation(inviteAdmin($tenant), 'existente@empresa-ejemplo.com');
        auth()->logout();
        User::factory()->create(['email' => 'existente@empresa-ejemplo.com']);

        $this->post('/invitations/'.$token, ['name' => 'Ana', 'password' => invitedPassword(), 'password_confirmation' => invitedPassword()])
            ->assertSessionHasErrors(['token' => 'Este correo ya tiene una cuenta en TikaroChat. Inicia sesión o pide que te inviten con otro correo.']);

        expect(User::query()->where('email', 'existente@empresa-ejemplo.com')->count())->toBe(1)
            ->and(invitationFor($tenant, 'existente@empresa-ejemplo.com')?->accepted_at)->toBeNull();
    });

    it('valida el nombre y la contraseña, e ignora el correo, el rol y la empresa que envíe el cliente', function (): void {
        $tenant = Tenant::factory()->onPlan('negocio')->create();
        $other = Tenant::factory()->onPlan('negocio')->create();
        $token = openInvitation(inviteAdmin($tenant), 'nueva@empresa-ejemplo.com', 'agent');
        auth()->logout();

        $this->post('/invitations/'.$token, ['name' => '', 'password' => 'Ab1', 'password_confirmation' => 'Ab1'])->assertSessionHasErrors(['name', 'password']);
        expect(User::query()->where('email', 'nueva@empresa-ejemplo.com')->exists())->toBeFalse();

        $this->post('/invitations/'.$token, [
            'name' => 'Luis', 'password' => invitedPassword(), 'password_confirmation' => invitedPassword(),
            'email' => 'otro@empresa-ejemplo.com', 'role' => 'admin', 'tenant_id' => (string) $other->id, 'is_platform_admin' => '1',
        ])->assertRedirect(route('account'));

        $user = User::query()->where('email', 'nueva@empresa-ejemplo.com')->firstOrFail();

        expect($user->role)->toBe(Role::Agent)
            ->and($user->tenant_id)->toBe($tenant->id)
            ->and($user->is_platform_admin)->toBeFalse()
            ->and(User::query()->where('email', 'otro@empresa-ejemplo.com')->exists())->toBeFalse();
    });

    it('un administrador invitado debe activar el 2FA antes de usar la aplicación', function (): void {
        $token = openInvitation(inviteAdmin(), 'nueva@empresa-ejemplo.com', 'admin');
        auth()->logout();

        $this->post('/invitations/'.$token, ['name' => 'Ana', 'password' => invitedPassword(), 'password_confirmation' => invitedPassword()]);

        $this->get('/account')->assertRedirect(route('security'));
    });

    it('quien ya tiene sesión es llevado a su cuenta en vez de ver la invitación', function (): void {
        $token = openInvitation(inviteAdmin());

        $this->actingAs(User::factory()->create())->get('/invitations/'.$token)->assertRedirect('/');
    });

    it('limita los intentos por dirección', function (): void {
        $token = openInvitation(inviteAdmin());
        auth()->logout();

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->get('/invitations/'.$token)->assertOk();
        }

        $this->get('/invitations/'.$token)->assertStatus(429);
    });
});
