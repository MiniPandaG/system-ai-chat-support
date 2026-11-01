<?php

declare(strict_types=1);

require_once __DIR__.'/../Tickets/Helpers.php';

use App\Attachments\Scanner;
use App\Broadcasting\LiveUpdates;
use App\Enums\Role;
use App\Events\InboxChanged;
use App\Events\TicketChanged;
use App\Jobs\ScanAttachment;
use App\Models\Ticket;
use App\Services\AuditLogger;
use App\Tenancy\TenantContext;
use App\Tickets\TicketStatus;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    useTemporaryAttachmentDisk();
    Queue::fake();
});

/**
 * Los avisos de un tipo que quedaron en la cola de difusión.
 *
 * @param  class-string  $event
 * @return Collection<int, TicketChanged|InboxChanged>
 */
function announced(string $event): Collection
{
    return Queue::pushed(BroadcastEvent::class)
        ->map(static fn (BroadcastEvent $job): object => $job->event)
        ->filter(static fn (object $candidate): bool => $candidate instanceof $event)
        ->values();
}

function channelsOf(TicketChanged|InboxChanged $event): array
{
    return array_map(static fn (PrivateChannel $channel): string => $channel->name, $event->broadcastOn());
}

describe('al responder', function (): void {
    it('avisa al ticket y a la bandeja de su empresa, con los identificadores y nada más', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $ticket = makeTicket($tenant);

        $this->actingAs($agent)->post("/tickets/{$ticket->id}/messages", ['body' => 'Texto que no debe viajar por el aviso.'])->assertRedirect();

        $ticketEvent = announced(TicketChanged::class)->sole();
        $inboxEvent = announced(InboxChanged::class)->sole();

        expect(channelsOf($ticketEvent))->toBe(["private-tenant.{$tenant->id}.tickets.{$ticket->id}"])
            ->and(channelsOf($inboxEvent))->toBe(["private-tenant.{$tenant->id}.inbox"])
            ->and($ticketEvent->broadcastWith())->toBe(['ticket' => $ticket->id])
            ->and($inboxEvent->broadcastWith())->toBe(['ticket' => $ticket->id])
            ->and($ticketEvent->broadcastAs())->toBe('ticket.changed')
            ->and($inboxEvent->broadcastAs())->toBe('inbox.changed');
    });

    it('el aviso nunca lleva texto, asunto, nombres ni correos', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $ticket = makeTicket($tenant, ['subject' => 'Asunto privado del cliente']);

        $this->actingAs($agent)->post("/tickets/{$ticket->id}/messages", ['body' => 'Mensaje privado'])->assertRedirect();

        $serialized = Queue::pushed(BroadcastEvent::class)->map(static fn (BroadcastEvent $job): string => json_encode([$job->event->broadcastWith(), $job->event->broadcastOn()], JSON_THROW_ON_ERROR))->implode('');

        expect($serialized)->not->toContain('privado')->not->toContain('Asunto')->not->toContain($agent->email);
    });

    it('un ticket cerrado rechaza la respuesta y no avisa de nada', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $ticket = makeTicket($tenant, ['status' => TicketStatus::Closed]);

        $this->actingAs($agent)->post("/tickets/{$ticket->id}/messages", ['body' => 'Tarde.'])->assertSessionHasErrors('body');

        Queue::assertNotPushed(BroadcastEvent::class);
    });

    it('quien no puede responder no provoca avisos', function (): void {
        $tenant = ticketTenant();
        $observer = ticketStaff($tenant, Role::Observer);
        $ticket = makeTicket($tenant);

        $this->actingAs($observer)->post("/tickets/{$ticket->id}/messages", ['body' => 'Hola'])->assertForbidden();

        Queue::assertNotPushed(BroadcastEvent::class);
    });

    it('una respuesta a un ticket de otra empresa da 404 y no avisa', function (): void {
        $agent = ticketStaff(ticketTenant());
        $foreign = makeTicket(ticketTenant());

        $this->actingAs($agent)->post("/tickets/{$foreign->id}/messages", ['body' => 'Hola'])->assertNotFound();

        Queue::assertNotPushed(BroadcastEvent::class);
    });
});

describe('al dejar una nota', function (): void {
    it('solo avisa al ticket: una nota no cambia lo que la bandeja muestra', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $ticket = makeTicket($tenant);

        $this->actingAs($agent)->post("/tickets/{$ticket->id}/notes", ['body' => 'Nota solo del equipo.'])->assertRedirect();

        expect(announced(TicketChanged::class))->toHaveCount(1)->and(announced(InboxChanged::class))->toHaveCount(0);
    });

    it('el aviso de una nota no lleva su texto', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $ticket = makeTicket($tenant);

        $this->actingAs($agent)->post("/tickets/{$ticket->id}/notes", ['body' => 'Secreto interno'])->assertRedirect();

        expect(announced(TicketChanged::class)->sole()->broadcastWith())->toBe(['ticket' => $ticket->id]);
    });

    it('un observador que recibe el aviso vuelve a pedir el ticket y sigue sin ver las notas', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $observer = ticketStaff($tenant, Role::Observer);
        $ticket = makeTicket($tenant);
        makeNote($ticket, $agent, ['body' => 'Nota que el observador no debe ver']);

        $page = $this->actingAs($observer)->get("/tickets/{$ticket->id}")->assertOk()->viewData('page');
        $thread = $page['props']['selected']['thread'];

        expect(collect($thread)->pluck('kind')->unique()->all())->toBe(['message'])
            ->and(json_encode($page['props'], JSON_THROW_ON_ERROR))->not->toContain('Nota que el observador');
    });

    it('un ticket cerrado rechaza la nota y no avisa', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $ticket = makeTicket($tenant, ['status' => TicketStatus::Closed]);

        $this->actingAs($agent)->post("/tickets/{$ticket->id}/notes", ['body' => 'Tarde.'])->assertSessionHasErrors('body');

        Queue::assertNotPushed(BroadcastEvent::class);
    });
});

describe('al cambiar un ticket', function (): void {
    it('un cambio de estado avisa al ticket y a la bandeja', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $ticket = makeTicket($tenant);

        $this->actingAs($agent)->patch("/tickets/{$ticket->id}", ['status' => 'resolved'])->assertRedirect();

        expect(announced(TicketChanged::class))->toHaveCount(1)->and(announced(InboxChanged::class))->toHaveCount(1);
    });

    it('guardar lo mismo que ya había no avisa de nada', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $ticket = makeTicket($tenant, ['status' => TicketStatus::Open]);

        $this->actingAs($agent)->patch("/tickets/{$ticket->id}", ['status' => 'open'])->assertRedirect();

        Queue::assertNotPushed(BroadcastEvent::class);
    });

    it('un cambio rechazado no avisa', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $ticket = makeTicket($tenant);

        $this->actingAs($agent)->patch("/tickets/{$ticket->id}", ['status' => 'inventado'])->assertSessionHasErrors('status');

        Queue::assertNotPushed(BroadcastEvent::class);
    });

    it('asignar a alguien avisa con el identificador del ticket, no con el de la persona', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $colleague = ticketStaff($tenant);
        $ticket = makeTicket($tenant);

        $this->actingAs($agent)->patch("/tickets/{$ticket->id}", ['assigned_to' => $colleague->id])->assertRedirect();

        expect(announced(InboxChanged::class)->sole()->broadcastWith())->toBe(['ticket' => $ticket->id]);
    });

    it('un observador no cambia nada ni provoca avisos', function (): void {
        $tenant = ticketTenant();
        $observer = ticketStaff($tenant, Role::Observer);
        $ticket = makeTicket($tenant);

        $this->actingAs($observer)->patch("/tickets/{$ticket->id}", ['status' => 'closed'])->assertForbidden();

        Queue::assertNotPushed(BroadcastEvent::class);
    });
});

describe('al crear un ticket', function (): void {
    it('avisa solo a la bandeja de la empresa y con el ticket nuevo', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);

        $this->actingAs($agent)->post('/tickets', newTicketPayload())->assertRedirect();

        $created = ticketsOf($tenant)->sole();
        $event = announced(InboxChanged::class)->sole();

        expect(channelsOf($event))->toBe(["private-tenant.{$tenant->id}.inbox"])
            ->and($event->broadcastWith())->toBe(['ticket' => $created->id])
            ->and(announced(TicketChanged::class))->toHaveCount(0);
    });

    it('un ticket rechazado por el límite del plan no avisa', function (): void {
        $tenant = ticketTenant('inicial');
        $agent = ticketStaff($tenant);
        foreach (range(1, 100) as $ignored) {
            makeTicket($tenant);
        }

        $this->actingAs($agent)->post('/tickets', newTicketPayload())->assertSessionHasErrors('limit');

        Queue::assertNotPushed(BroadcastEvent::class);
    });
});

describe('al etiquetar', function (): void {
    it('poner una etiqueta avisa al ticket y a la bandeja, y ponerla otra vez no avisa', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $ticket = makeTicket($tenant);
        $tag = makeTag($tenant);

        $this->actingAs($agent)->post("/tickets/{$ticket->id}/tags", ['tag_id' => $tag->id])->assertRedirect();
        expect(announced(TicketChanged::class))->toHaveCount(1)->and(announced(InboxChanged::class))->toHaveCount(1);

        $this->actingAs($agent)->post("/tickets/{$ticket->id}/tags", ['tag_id' => $tag->id])->assertRedirect();
        expect(announced(TicketChanged::class))->toHaveCount(1);
    });

    it('quitar una etiqueta avisa, y quitar una que no estaba no avisa', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $ticket = makeTicket($tenant);
        $tag = makeTag($tenant);
        $this->actingAs($agent)->post("/tickets/{$ticket->id}/tags", ['tag_id' => $tag->id]);
        Queue::fake();

        $this->actingAs($agent)->delete("/tickets/{$ticket->id}/tags/{$tag->id}")->assertRedirect();
        expect(announced(TicketChanged::class))->toHaveCount(1)->and(announced(InboxChanged::class))->toHaveCount(1);

        Queue::fake();
        $this->actingAs($agent)->delete("/tickets/{$ticket->id}/tags/{$tag->id}")->assertRedirect();
        Queue::assertNotPushed(BroadcastEvent::class);
    });
});

describe('al revisar un adjunto', function (): void {
    it('cuando el antivirus termina avisa al ticket de su empresa, sin que nadie tenga que preguntar', function (): void {
        $tenant = ticketTenant();
        $attachment = pendingAttachment($tenant);

        app(TenantContext::class)->run($tenant, fn () => (new ScanAttachment($attachment->id))->handle(app(Scanner::class), app(AuditLogger::class)));

        $event = announced(TicketChanged::class)->sole();
        expect(channelsOf($event))->toBe(["private-tenant.{$tenant->id}.tickets.{$attachment->ticket_id}"])
            ->and(announced(InboxChanged::class))->toHaveCount(0);
    });

    it('revisar otra vez un adjunto ya revisado no repite el aviso', function (): void {
        $tenant = ticketTenant();
        $attachment = pendingAttachment($tenant);
        $run = fn () => app(TenantContext::class)->run($tenant, fn () => (new ScanAttachment($attachment->id))->handle(app(Scanner::class), app(AuditLogger::class)));

        $run();
        $run();

        expect(announced(TicketChanged::class))->toHaveCount(1);
    });
});

describe('después de confirmar', function (): void {
    it('si la transacción se deshace, el aviso no sale', function (): void {
        $ticket = makeTicket(ticketTenant());

        try {
            DB::transaction(function () use ($ticket): void {
                LiveUpdates::both($ticket);

                throw new RuntimeException('se deshace');
            });
        } catch (RuntimeException) {
            // Es lo que se quiere comprobar: la transacción falla y los avisos se descartan.
        }

        Queue::assertNotPushed(BroadcastEvent::class);
    });

    it('si la transacción se confirma, el aviso sale una vez confirmada', function (): void {
        $ticket = makeTicket(ticketTenant());

        DB::transaction(function () use ($ticket): void {
            LiveUpdates::both($ticket);

            Queue::assertNotPushed(BroadcastEvent::class);
        });

        expect(announced(TicketChanged::class))->toHaveCount(1)->and(announced(InboxChanged::class))->toHaveCount(1);
    });
});

describe('forma de los avisos', function (): void {
    it('solo usan canales privados con la empresa en el nombre y se envían después de confirmar', function (): void {
        $events = [new TicketChanged(7, 9), new InboxChanged(7, 9)];

        foreach ($events as $event) {
            expect($event)->toBeInstanceOf(ShouldBroadcast::class)
                ->toBeInstanceOf(ShouldDispatchAfterCommit::class)
                ->and($event->broadcastOn())->toHaveCount(1)
                ->and($event->broadcastOn()[0])->toBeInstanceOf(PrivateChannel::class)
                ->and($event->broadcastOn()[0]->name)->toStartWith('private-tenant.7.');
        }
    });

    it('un aviso que no se puede entregar se reintenta pocas veces y no tumba la petición', function (): void {
        expect((new TicketChanged(1, 1))->tries)->toBeLessThanOrEqual(3)->and((new TicketChanged(1, 1))->timeout)->toBeLessThanOrEqual(10)
            ->and((new InboxChanged(1, 1))->tries)->toBeLessThanOrEqual(3);
    });

    it('el cambio de una empresa nunca se anuncia en el canal de otra', function (): void {
        $tenantA = ticketTenant();
        $tenantB = ticketTenant();
        $agentA = ticketStaff($tenantA);
        $ticketA = makeTicket($tenantA);
        makeTicket($tenantB);

        $this->actingAs($agentA)->post("/tickets/{$ticketA->id}/messages", ['body' => 'Hola']);

        $all = Queue::pushed(BroadcastEvent::class)->flatMap(static fn (BroadcastEvent $job): array => channelsOf($job->event));
        expect($all->every(static fn (string $name): bool => str_starts_with($name, "private-tenant.{$tenantA->id}.")))->toBeTrue()
            ->and(Ticket::query()->withoutGlobalScopes()->count())->toBeGreaterThanOrEqual(2);
    });
});
