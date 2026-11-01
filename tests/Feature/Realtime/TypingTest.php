<?php

declare(strict_types=1);

require_once __DIR__.'/../Tickets/Helpers.php';

use App\Enums\Role;
use App\Events\TicketTyping;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return Collection<int, TicketTyping> */
function typingSignals(): Collection
{
    return Queue::pushed(BroadcastEvent::class)
        ->map(static fn (BroadcastEvent $job): object => $job->event)
        ->filter(static fn (object $event): bool => $event instanceof TicketTyping)
        ->values();
}

describe('quién puede avisar que escribe', function (): void {
    it('un agente avisa y el servidor lo anuncia en el canal de ese ticket, con su identificador y nada más', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $ticket = makeTicket($tenant);

        $this->actingAs($agent)->post("/tickets/{$ticket->id}/typing")->assertNoContent();

        $signal = typingSignals()->sole();
        $channel = $signal->broadcastOn()[0];
        expect($channel)->toBeInstanceOf(PrivateChannel::class)
            ->and($channel->name)->toBe("private-tenant.{$tenant->id}.tickets.{$ticket->id}")
            ->and($signal->broadcastWith())->toBe(['user' => $agent->id])
            ->and($signal->broadcastAs())->toBe('ticket.typing');
    });

    it('un administrador también avisa', function (): void {
        $tenant = ticketTenant();
        $admin = ticketStaff($tenant, Role::Admin);
        $ticket = makeTicket($tenant);

        $this->actingAs($admin)->post("/tickets/{$ticket->id}/typing")->assertNoContent();

        expect(typingSignals())->toHaveCount(1);
    });

    it('un observador no puede avisar: no escribe en el ticket', function (): void {
        $tenant = ticketTenant();
        $observer = ticketStaff($tenant, Role::Observer);
        $ticket = makeTicket($tenant);

        $this->actingAs($observer)->post("/tickets/{$ticket->id}/typing")->assertForbidden();

        expect(typingSignals())->toHaveCount(0);
    });

    it('un ticket de otra empresa da 404 y no emite nada', function (): void {
        $agent = ticketStaff(ticketTenant());
        $foreign = makeTicket(ticketTenant());

        $this->actingAs($agent)->post("/tickets/{$foreign->id}/typing")->assertNotFound();

        expect(typingSignals())->toHaveCount(0);
    });

    it('un ticket que no existe da 404', function (): void {
        $agent = ticketStaff(ticketTenant());

        $this->actingAs($agent)->post('/tickets/999999/typing')->assertNotFound();
    });

    it('un visitante no puede avisar', function (): void {
        $ticket = makeTicket(ticketTenant());

        $this->postJson("/tickets/{$ticket->id}/typing")->assertUnauthorized();

        expect(typingSignals())->toHaveCount(0);
    });

    it('un usuario desactivado no puede avisar', function (): void {
        $tenant = ticketTenant();
        $disabled = ticketStaff($tenant, Role::Agent, disabled: true);
        $ticket = makeTicket($tenant);

        $this->actingAs($disabled)->post("/tickets/{$ticket->id}/typing")->assertRedirect(route('login'));

        expect(typingSignals())->toHaveCount(0);
    });

    it('el personal de la plataforma no puede avisar', function (): void {
        $ticket = makeTicket(ticketTenant());
        $staff = User::factory()->create(['is_platform_admin' => true]);
        $staff->forceFill(['two_factor_secret' => encrypt('S'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()])->save();

        $this->actingAs($staff)->post("/tickets/{$ticket->id}/typing")->assertNotFound();

        expect(typingSignals())->toHaveCount(0);
    });

    it('el servidor no acepta de quién es la señal: ignora cualquier identificador que envíe el navegador', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $colleague = ticketStaff($tenant);
        $ticket = makeTicket($tenant);

        $this->actingAs($agent)->post("/tickets/{$ticket->id}/typing", ['user' => $colleague->id, 'user_id' => $colleague->id])->assertNoContent();

        expect(typingSignals()->sole()->broadcastWith())->toBe(['user' => $agent->id]);
    });
});

describe('límites', function (): void {
    it('no admite más de una señal cada 2 segundos por persona y ticket', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $ticket = makeTicket($tenant);

        $this->actingAs($agent)->post("/tickets/{$ticket->id}/typing")->assertNoContent();
        $this->actingAs($agent)->post("/tickets/{$ticket->id}/typing")->assertTooManyRequests();

        expect(typingSignals())->toHaveCount(1);
    });

    it('pasados 2 segundos vuelve a admitir otra', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $ticket = makeTicket($tenant);
        Carbon::setTestNow(now());
        $this->actingAs($agent)->post("/tickets/{$ticket->id}/typing")->assertNoContent();

        Carbon::setTestNow(now()->addSeconds(3));

        $this->actingAs($agent)->post("/tickets/{$ticket->id}/typing")->assertNoContent();
    });

    it('no admite más de 30 por minuto aunque se espacien', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $ticket = makeTicket($tenant);
        Carbon::setTestNow(now());

        foreach (range(1, 30) as $ignored) {
            $this->actingAs($agent)->post("/tickets/{$ticket->id}/typing")->assertNoContent();
            Carbon::setTestNow(now()->addSeconds(2));
        }
        // 30 señales en 58 s: la 31 cae dentro del mismo minuto.
        Carbon::setTestNow(now()->subSeconds(1));

        $this->actingAs($agent)->post("/tickets/{$ticket->id}/typing")->assertTooManyRequests();
    });

    it('el límite es de cada persona y de cada ticket', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $colleague = ticketStaff($tenant);
        $first = makeTicket($tenant);
        $second = makeTicket($tenant);
        $this->actingAs($agent)->post("/tickets/{$first->id}/typing")->assertNoContent();

        $this->actingAs($colleague)->post("/tickets/{$first->id}/typing")->assertNoContent();
        $this->actingAs($agent)->post("/tickets/{$second->id}/typing")->assertNoContent();

        expect(typingSignals())->toHaveCount(3);
    });
});

describe('forma de la señal', function (): void {
    it('es pasajera: no se reintenta y no lleva texto ni nombres', function (): void {
        $signal = new TicketTyping(3, 4, 5);

        expect($signal->tries)->toBe(1)
            ->and($signal->broadcastWith())->toBe(['user' => 5])
            ->and(json_encode($signal->broadcastWith(), JSON_THROW_ON_ERROR))->not->toContain('name')
            ->and($signal)->not->toBeInstanceOf(ShouldDispatchAfterCommit::class);
    });
});
