<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Broadcasting\Channels;
use App\Enums\Role;
use App\Events\KnowledgeChanged;
use App\Knowledge\SourceKind;
use App\Models\User;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'llave-de-prueba',
        'broadcasting.connections.reverb.secret' => 'secreto-de-prueba',
        'broadcasting.connections.reverb.app_id' => '1',
    ]);
    require base_path('routes/channels.php');
});

function authorizeKnowledge(object $test, ?User $user, string $channel): TestResponse
{
    $request = $user === null ? $test : $test->actingAs($user);

    return $request->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel]);
}

describe('el canal de la base de conocimiento', function (): void {
    it('lleva la empresa en el nombre y es privado', function (): void {
        expect(Channels::knowledge(7))->toBe('tenant.7.knowledge');
        $event = new KnowledgeChanged(7, SourceKind::Article, 12);

        expect(array_map(fn ($channel): string => $channel->name, $event->broadcastOn()))->toBe(['private-tenant.7.knowledge'])
            ->and($event->broadcastAs())->toBe('knowledge.changed')
            ->and($event->broadcastWith())->toBe(['kind' => 'article', 'id' => 12]);
    });

    it('entran el administrador y el agente de la empresa, no el observador', function (): void {
        $tenant = ticketTenant();

        authorizeKnowledge($this, kbAdmin($tenant), 'private-'.Channels::knowledge($tenant->id))->assertOk();
        authorizeKnowledge($this, ticketStaff($tenant, Role::Agent), 'private-'.Channels::knowledge($tenant->id))->assertOk();
        authorizeKnowledge($this, ticketStaff($tenant, Role::Observer), 'private-'.Channels::knowledge($tenant->id))->assertForbidden();
    });

    it('no entra quien es de otra empresa, ni sin sesión, ni a un canal mal formado', function (): void {
        $mine = ticketTenant();
        $theirs = ticketTenant();
        $admin = kbAdmin($mine);

        authorizeKnowledge($this, $admin, 'private-'.Channels::knowledge($theirs->id))->assertForbidden();
        authorizeKnowledge($this, $admin, 'private-tenant.0.knowledge')->assertForbidden();
        authorizeKnowledge($this, $admin, 'private-tenant.'.$mine->id.'.knowledge.extra')->assertForbidden();
        authorizeKnowledge($this, $admin, 'tenant.'.$mine->id.'.knowledge')->assertForbidden();
    });

    it('sin sesión no entra', function (): void {
        $tenant = ticketTenant();

        authorizeKnowledge($this, null, 'private-'.Channels::knowledge($tenant->id))->assertUnauthorized();
    });

    it('un administrador desactivado no entra', function (): void {
        $tenant = ticketTenant();

        authorizeKnowledge($this, ticketStaff($tenant, Role::Admin, disabled: true), 'private-'.Channels::knowledge($tenant->id))->assertStatus(302);
    });
});
