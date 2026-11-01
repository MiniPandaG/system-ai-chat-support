<?php

declare(strict_types=1);

require_once __DIR__.'/../Tickets/Helpers.php';

use App\Enums\Role;
use App\Events\TicketChanged;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketRead;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tickets\MessageAuthor;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** Un mensaje nuevo en el ticket: del cliente (sin autor del equipo) o de una persona del equipo. */
function postMessage(Ticket $ticket, ?User $author, ?Carbon $at = null): TicketMessage
{
    return app(TenantContext::class)->run($ticket->tenant()->firstOrFail(), function () use ($ticket, $author, $at): TicketMessage {
        $message = new TicketMessage(['body' => 'Otro mensaje']);
        $message->forceFill(['ticket_id' => $ticket->id, 'author' => $author === null ? MessageAuthor::Customer : MessageAuthor::Agent, 'user_id' => $author?->id]);
        if ($at !== null) {
            $message->forceFill(['created_at' => $at, 'updated_at' => $at]);
        }
        $message->save();
        Ticket::query()->whereKey($ticket->id)->update(['last_message_at' => $message->created_at]);

        return $message;
    });
}

/** @return list<array<string, mixed>> */
function rowsFor(object $test, User $user): array
{
    return $test->actingAs($user)->get('/tickets')->assertOk()->viewData('page')['props']['tickets'];
}

function unreadOf(object $test, User $user, Ticket $ticket): bool
{
    $row = collect(rowsFor($test, $user))->firstWhere('id', $ticket->id);

    return $row['unread'];
}

function readRowOf(Ticket $ticket, User $user): ?TicketRead
{
    return app(TenantContext::class)->run($ticket->tenant()->firstOrFail(), fn () => TicketRead::query()->where('ticket_id', $ticket->id)->where('user_id', $user->id)->first());
}

describe('sin leer', function (): void {
    it('un ticket con un mensaje del cliente está sin leer para quien nunca lo abrió', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $ticket = makeTicket($tenant);

        expect(unreadOf($this, $agent, $ticket))->toBeTrue();
    });

    it('abrir el ticket lo deja leído', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $ticket = makeTicket($tenant);

        $this->actingAs($agent)->get("/tickets/{$ticket->id}")->assertOk();

        expect(unreadOf($this, $agent, $ticket))->toBeFalse();
    });

    it('un mensaje nuevo del cliente lo vuelve a dejar sin leer', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $ticket = makeTicket($tenant);
        $this->actingAs($agent)->get("/tickets/{$ticket->id}");

        postMessage($ticket, null, now()->addSeconds(5));

        expect(unreadOf($this, $agent, $ticket))->toBeTrue();
    });

    it('lo que escribe uno mismo no lo deja sin leer, pero lo que escribe un compañero sí', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $colleague = ticketStaff($tenant);
        $ticket = makeTicket($tenant);
        $this->actingAs($agent)->get("/tickets/{$ticket->id}");

        postMessage($ticket, $agent, now()->addSeconds(5));
        expect(unreadOf($this, $agent, $ticket))->toBeFalse();

        postMessage($ticket, $colleague, now()->addSeconds(10));
        expect(unreadOf($this, $agent, $ticket))->toBeTrue();
    });

    it('una nota interna nunca deja un ticket sin leer', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $colleague = ticketStaff($tenant);
        $ticket = makeTicket($tenant);
        $this->actingAs($agent)->get("/tickets/{$ticket->id}");

        makeNote($ticket, $colleague, ['created_at' => now()->addSeconds(5)]);

        expect(unreadOf($this, $agent, $ticket))->toBeFalse();
    });

    it('el estado de lectura es de cada persona: lo que leo yo no cambia lo que ve mi compañero', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $colleague = ticketStaff($tenant);
        $ticket = makeTicket($tenant);

        $this->actingAs($agent)->get("/tickets/{$ticket->id}");

        expect(unreadOf($this, $agent, $ticket))->toBeFalse()->and(unreadOf($this, $colleague, $ticket))->toBeTrue();
    });

    it('un observador tiene su propio estado de lectura', function (): void {
        $tenant = ticketTenant();
        $observer = ticketStaff($tenant, Role::Observer);
        $ticket = makeTicket($tenant);
        expect(unreadOf($this, $observer, $ticket))->toBeTrue();

        $this->actingAs($observer)->get("/tickets/{$ticket->id}")->assertOk();

        expect(unreadOf($this, $observer, $ticket))->toBeFalse();
    });

    it('la lista de tickets sin ticket abierto no marca nada como leído', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $ticket = makeTicket($tenant);

        rowsFor($this, $agent);

        expect(readRowOf($ticket, $agent))->toBeNull();
    });

    it('los tramos siguientes de la lista también dicen qué está sin leer', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $ticket = makeTicket($tenant);

        $rows = $this->actingAs($agent)->getJson('/tickets/page')->assertOk()->json('data');

        expect(collect($rows)->firstWhere('id', $ticket->id)['unread'])->toBeTrue();
    });
});

describe('al abrir un ticket', function (): void {
    it('guarda hasta cuándo lo leyó esa persona, en una sola fila', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $ticket = makeTicket($tenant);

        $this->actingAs($agent)->get("/tickets/{$ticket->id}");
        $this->actingAs($agent)->get("/tickets/{$ticket->id}");

        $read = readRowOf($ticket, $agent);
        expect($read)->not->toBeNull()->and($read->tenant_id)->toBe($tenant->id)
            ->and(app(TenantContext::class)->run($tenant, fn () => TicketRead::query()->count()))->toBe(1);
    });

    it('avisa a quien tiene el ticket abierto solo cuando había algo nuevo que leer', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $ticket = makeTicket($tenant);

        $this->actingAs($agent)->get("/tickets/{$ticket->id}");
        expect(Queue::pushed(BroadcastEvent::class)->filter(fn (BroadcastEvent $job): bool => $job->event instanceof TicketChanged))->toHaveCount(1);

        $this->actingAs($agent)->get("/tickets/{$ticket->id}");
        expect(Queue::pushed(BroadcastEvent::class)->filter(fn (BroadcastEvent $job): bool => $job->event instanceof TicketChanged))->toHaveCount(1);
    });

    it('un mensaje nuevo mientras está abierto vuelve a marcarse al pedir el ticket otra vez', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $ticket = makeTicket($tenant);
        $this->actingAs($agent)->get("/tickets/{$ticket->id}");
        $first = readRowOf($ticket, $agent)->last_read_at;

        Carbon::setTestNow(now()->addMinute());
        postMessage($ticket, null, now());
        $this->actingAs($agent)->get("/tickets/{$ticket->id}");

        expect(readRowOf($ticket, $agent)->last_read_at->greaterThan($first))->toBeTrue()
            ->and(unreadOf($this, $agent, $ticket))->toBeFalse();
    });

    it('un ticket de otra empresa da 404 y no deja ninguna marca', function (): void {
        $agent = ticketStaff(ticketTenant());
        $foreign = makeTicket(ticketTenant());

        $this->actingAs($agent)->get("/tickets/{$foreign->id}")->assertNotFound();

        expect(readRowOf($foreign, $agent))->toBeNull();
    });

    it('el personal de la plataforma no abre tickets ni deja marcas', function (): void {
        $tenant = ticketTenant();
        $ticket = makeTicket($tenant);
        $staff = User::factory()->create(['is_platform_admin' => true]);
        $staff->forceFill(['two_factor_secret' => encrypt('S'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()])->save();

        $this->actingAs($staff)->get("/tickets/{$ticket->id}")->assertNotFound();

        expect(app(TenantContext::class)->run($tenant, fn () => TicketRead::query()->count()))->toBe(0);
    });

    it('la fila de lectura de una empresa no se ve desde otra', function (): void {
        $tenantA = ticketTenant();
        $tenantB = ticketTenant();
        $agentA = ticketStaff($tenantA);
        $ticketA = makeTicket($tenantA);
        $this->actingAs($agentA)->get("/tickets/{$ticketA->id}");

        $visibleFromB = app(TenantContext::class)->run($tenantB, fn () => TicketRead::query()->count());
        $withoutContext = TicketRead::query()->count();

        expect($visibleFromB)->toBe(0)->and($withoutContext)->toBe(0);
    });
});

describe('visto por', function (): void {
    function seenNames(object $test, User $viewer, Ticket $ticket): array
    {
        return $test->actingAs($viewer)->get("/tickets/{$ticket->id}")->assertOk()->viewData('page')['props']['selected']['seen_by'];
    }

    it('lista a los compañeros que ya vieron lo último, sin contar a quien mira', function (): void {
        $tenant = ticketTenant();
        $ana = ticketStaff($tenant);
        $luis = ticketStaff($tenant);
        $ticket = makeTicket($tenant);
        $this->actingAs($luis)->get("/tickets/{$ticket->id}");

        $names = seenNames($this, $ana, $ticket);

        expect($names)->toBe([$luis->name])->and($names)->not->toContain($ana->name);
    });

    it('quien leyó antes del último mensaje ya no aparece', function (): void {
        $tenant = ticketTenant();
        $ana = ticketStaff($tenant);
        $luis = ticketStaff($tenant);
        $ticket = makeTicket($tenant);
        $this->actingAs($luis)->get("/tickets/{$ticket->id}");

        postMessage($ticket, null, now()->addMinute());

        expect(seenNames($this, $ana, $ticket))->toBe([]);
    });

    it('un compañero desactivado no aparece', function (): void {
        $tenant = ticketTenant();
        $ana = ticketStaff($tenant);
        $luis = ticketStaff($tenant);
        $ticket = makeTicket($tenant);
        $this->actingAs($luis)->get("/tickets/{$ticket->id}");
        $luis->forceFill(['disabled_at' => now()])->save();

        expect(seenNames($this, $ana, $ticket))->toBe([]);
    });

    it('un observador que lo vio también aparece, y no se mezcla con otra empresa', function (): void {
        $tenant = ticketTenant();
        $ana = ticketStaff($tenant);
        $observer = ticketStaff($tenant, Role::Observer);
        $other = ticketStaff(ticketTenant());
        $ticket = makeTicket($tenant);
        $this->actingAs($observer)->get("/tickets/{$ticket->id}");
        makeTicket($other->tenant()->firstOrFail());

        expect(seenNames($this, $ana, $ticket))->toBe([$observer->name]);
    });

    it('muestra hasta cinco nombres y cuenta el resto', function (): void {
        $tenant = ticketTenant();
        $viewer = ticketStaff($tenant);
        $ticket = makeTicket($tenant);
        foreach (range(1, 7) as $ignored) {
            $this->actingAs(ticketStaff($tenant))->get("/tickets/{$ticket->id}");
        }

        $props = $this->actingAs($viewer)->get("/tickets/{$ticket->id}")->viewData('page')['props']['selected'];

        expect($props['seen_by'])->toHaveCount(5)->and($props['seen_more'])->toBe(2);
    });
});
