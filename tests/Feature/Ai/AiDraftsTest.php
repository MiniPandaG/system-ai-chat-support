<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';
require_once __DIR__.'/../Mail/Helpers.php';

use App\Ai\AiChannel;
use App\Ai\AiMode;
use App\Ai\AiReason;
use App\Ai\AiReplies;
use App\Ai\AiRunStatus;
use App\Ai\Completion;
use App\Ai\CompletionRequest;
use App\Ai\Providers\FakeModel;
use App\Enums\Role;
use App\Jobs\GenerateAiReply;
use App\Mail\Inbound\InboundBody;
use App\Mail\Inbound\InboundMail;
use App\Mail\Inbound\InboundMailProcessor;
use App\Models\AiRun;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Tenancy\TenantContext;
use App\Tickets\MessageAuthor;
use App\Tickets\TicketStatus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    // El vector de prueba solo compara palabras: su escala de parecido es otra, así que la de confianza se ajusta a ella.
    config(['ai.reply.min_score' => 0.2, 'ai.confidence.floor' => 0.1, 'ai.confidence.ceil' => 0.3, 'mail.from.address' => 'soporte@tikarochat.test']);
    useTemporaryAttachmentDisk();
});

function fakeAnswers(?Closure $handler = null): void
{
    app(FakeModel::class)->handler = $handler ?? fn (CompletionRequest $r): Completion => new Completion(replyJson(), 900, 60, 'stop');
}

/** @return list<AiRun> */
function runsOf(Tenant $tenant): array
{
    return app(TenantContext::class)->run($tenant, fn (): array => AiRun::query()->orderBy('id')->get()->all());
}

function draftedRun(Tenant $tenant, ?Ticket $ticket = null): AiRun
{
    $ticket ??= makeTicket($tenant);
    $trigger = conversationMessage($tenant, $ticket, MessageAuthor::Customer, '¿Cuántos días tengo para hacer devoluciones de productos?');

    return app(TenantContext::class)->run($tenant, function () use ($ticket, $trigger): AiRun {
        $run = new AiRun;
        $run->forceFill([
            'ticket_id' => $ticket->id,
            'trigger_message_id' => $trigger->id,
            'channel' => 'widget',
            'mode' => 'shadow',
            'status' => AiRunStatus::Drafted,
            'reason' => AiReason::Answered,
            'catalog_id' => 'fake-fast',
            'top_score' => 0.8,
            'body' => 'Tienes 30 días para devolverlo.',
            'sources' => [['kind' => 'article', 'id' => 1, 'title' => 'Política de devoluciones', 'chunk_id' => 1]],
        ])->save();

        return $run;
    });
}

function approveDraft(object $test, $user, Ticket $ticket, AiRun $run, array $payload = []): TestResponse
{
    return $test->actingAs($user)->post("/tickets/{$ticket->id}/ai/{$run->id}/approve", $payload);
}

function aiInboundMail(Tenant $tenant, Ticket $ticket, string $text): InboundMail
{
    return new InboundMail(
        handle: new stdClass,
        messageId: '<'.bin2hex(random_bytes(8)).'@cliente.example>',
        from: 'marisol@cliente-ejemplo.com',
        recipients: 'Soporte <soporte+t-'.threadToken($tenant, $ticket).'@tikarochat.test>',
        references: '',
        headers: [],
        size: 2048,
        body: fn (): InboundBody => new InboundBody($text, '', []),
    );
}

describe('el disparo', function (): void {
    it('un mensaje del widget con la IA en borrador prepara un borrador y no responde solo', function (): void {
        $tenant = aiTenant();
        publishedKnowledge($this, $tenant);
        fakeAnswers();

        $token = widgetStart($this, $tenant, ['body' => '¿Cuántos días tengo para hacer devoluciones de productos?']);

        $ticket = widgetTicketOf($tenant, $token);
        $run = runsOf($tenant)[0];
        expect($run->status)->toBe(AiRunStatus::Drafted)->and($run->body)->toBe('Tienes 30 días para devolverlo.

Fuente: Política de devoluciones')->and($run->channel)->toBe('widget')->and($run->mode)->toBe('shadow')
            ->and($run->ticket_id)->toBe($ticket->id)
            ->and(widgetBodies($tenant, $ticket))->toBe(['¿Cuántos días tengo para hacer devoluciones de productos?']);
    });

    it('con la IA apagada en ese canal no se encola nada', function (): void {
        $tenant = aiTenant(['widget_mode' => AiMode::Off]);
        Queue::fake();

        widgetStart($this, $tenant);

        Queue::assertNotPushed(GenerateAiReply::class);
    });

    it('sin ajustes guardados la IA está apagada', function (): void {
        $tenant = widgetTenant();
        Queue::fake();

        widgetStart($this, $tenant);

        Queue::assertNotPushed(GenerateAiReply::class);
    });

    it('la IA encendida en el correo y apagada en el widget solo se mueve con el correo', function (): void {
        $tenant = aiTenant(['widget_mode' => AiMode::Off]);
        Queue::fake();
        widgetStart($this, $tenant);
        Queue::assertNotPushed(GenerateAiReply::class);

        // La conversación del widget ya usó el número 1: el ayudante de tickets numera con lo que tiene en la mano.
        $tenant->refresh();
        $ticket = mailTicket($tenant);
        app(InboundMailProcessor::class)->process(aiInboundMail($tenant, $ticket, 'Quiero saber de mi devolución.'));

        Queue::assertPushed(GenerateAiReply::class, fn (GenerateAiReply $job): bool => $job->channel === 'email' && $job->ticketId === $ticket->id);
    });

    it('un correo del cliente prepara un borrador', function (): void {
        $tenant = aiTenant();
        publishedKnowledge($this, $tenant);
        fakeAnswers();
        $ticket = mailTicket($tenant);

        app(InboundMailProcessor::class)->process(aiInboundMail($tenant, $ticket, '¿Cuántos días tengo para hacer devoluciones de productos?'));

        $run = runsOf($tenant)[0];
        expect($run->status)->toBe(AiRunStatus::Drafted)->and($run->channel)->toBe('email')->and($run->ticket_id)->toBe($ticket->id);
    });

    it('un mensaje del equipo o una nota no ponen a trabajar a la IA', function (): void {
        $tenant = aiTenant();
        Queue::fake();
        $ticket = makeTicket($tenant);
        $agentMessage = conversationMessage($tenant, $ticket, MessageAuthor::Agent, 'Hola, soy el equipo.');

        app(TenantContext::class)->run($tenant, fn () => app(AiReplies::class)->afterCustomerMessage($ticket, $agentMessage, AiChannel::Widget));

        Queue::assertNotPushed(GenerateAiReply::class);
    });
});

describe('el trabajo', function (): void {
    function runJob(Tenant $tenant, Ticket $ticket, TicketMessage $message, string $channel = 'widget'): void
    {
        app(TenantContext::class)->run($tenant, fn () => app()->call([new GenerateAiReply($ticket->id, $message->id, $channel), 'handle']));
    }

    it('no interviene en un ticket cerrado ni en uno que ya atiende una persona, y lo deja anotado', function (string $kind, AiReason $reason): void {
        $tenant = aiTenant();
        publishedKnowledge($this, $tenant);
        fakeAnswers();
        $staff = ticketStaff($tenant);
        $ticket = makeTicket($tenant, $kind === 'closed' ? ['status' => TicketStatus::Closed] : ['assigned_to' => $staff->id]);
        $message = conversationMessage($tenant, $ticket, MessageAuthor::Customer, '¿Cuántos días tengo para hacer devoluciones de productos?');

        runJob($tenant, $ticket, $message);

        $run = runsOf($tenant)[0];
        expect($run->status)->toBe(AiRunStatus::Skipped)->and($run->reason)->toBe($reason)->and($run->body)->toBeNull()->and(app(FakeModel::class)->requests)->toBe([]);
    })->with([['closed', AiReason::TicketClosed], ['assigned', AiReason::Assigned]]);

    it('si el cliente escribió algo más nuevo, esta respuesta sobra y no se llama al modelo', function (): void {
        $tenant = aiTenant();
        publishedKnowledge($this, $tenant);
        fakeAnswers();
        $ticket = makeTicket($tenant);
        $first = conversationMessage($tenant, $ticket, MessageAuthor::Customer, '¿Cuántos días tengo para hacer devoluciones?');
        conversationMessage($tenant, $ticket, MessageAuthor::Customer, 'Perdón, de productos.');

        runJob($tenant, $ticket, $first);

        expect(runsOf($tenant))->toBe([])->and(app(FakeModel::class)->requests)->toBe([]);
    });

    it('si la IA se apagó entre que se encoló y se ejecutó, no hace nada', function (): void {
        $tenant = aiTenant(['widget_mode' => AiMode::Off]);
        publishedKnowledge($this, $tenant);
        fakeAnswers();
        $ticket = makeTicket($tenant);
        $message = conversationMessage($tenant, $ticket, MessageAuthor::Customer, '¿Cuántos días tengo para hacer devoluciones de productos?');

        runJob($tenant, $ticket, $message);

        expect(runsOf($tenant))->toBe([])->and(app(FakeModel::class)->requests)->toBe([]);
    });

    it('un modelo elegido que ya no está disponible deja la ejecución como omitida, sin llamar a nadie', function (): void {
        $tenant = aiTenant(['reply_model' => 'deepseek-fast']);
        publishedKnowledge($this, $tenant);
        fakeAnswers();
        $ticket = makeTicket($tenant);
        $message = conversationMessage($tenant, $ticket, MessageAuthor::Customer, '¿Cuántos días tengo para hacer devoluciones de productos?');

        runJob($tenant, $ticket, $message);

        expect(runsOf($tenant)[0]->reason)->toBe(AiReason::ModelUnavailable)->and(runsOf($tenant)[0]->status)->toBe(AiRunStatus::Skipped);
    });

    it('un mensaje que no es del ticket o no es del cliente se ignora', function (): void {
        $tenant = aiTenant();
        fakeAnswers();
        $ticket = makeTicket($tenant);
        $other = makeTicket($tenant);
        $foreign = conversationMessage($tenant, $other, MessageAuthor::Customer, 'Otra conversación.');
        $own = conversationMessage($tenant, $ticket, MessageAuthor::Agent, 'Del equipo.');

        runJob($tenant, $ticket, $foreign);
        runJob($tenant, $ticket, $own);

        expect(runsOf($tenant))->toBe([]);
    });

    it('si el trabajo falla a medias, la ejecución queda como fallida y no se queda «preparando»', function (): void {
        $tenant = aiTenant();
        $ticket = makeTicket($tenant);
        $message = conversationMessage($tenant, $ticket, MessageAuthor::Customer, 'Hola');
        app(TenantContext::class)->run($tenant, function () use ($ticket, $message): void {
            $run = new AiRun;
            $run->forceFill(['ticket_id' => $ticket->id, 'trigger_message_id' => $message->id, 'status' => AiRunStatus::Pending, 'reason' => AiReason::Answered])->save();
            (new GenerateAiReply($ticket->id, $message->id, 'widget'))->failed(new RuntimeException('boom'));
        });

        expect(runsOf($tenant)[0]->status)->toBe(AiRunStatus::Failed);
    });
});

describe('aprobar un borrador', function (): void {
    it('enviarlo tal cual crea un mensaje de la IA aprobado por la persona, que el cliente ve como asistente virtual', function (): void {
        $tenant = aiTenant();
        publishedKnowledge($this, $tenant);
        fakeAnswers();
        $token = widgetStart($this, $tenant, ['body' => '¿Cuántos días tengo para hacer devoluciones de productos?']);
        $ticket = widgetTicketOf($tenant, $token);
        $agent = ticketStaff($tenant);
        $run = runsOf($tenant)[0];

        approveDraft($this, $agent, $ticket, $run)->assertSessionHasNoErrors();

        $message = app(TenantContext::class)->run($tenant, fn () => TicketMessage::query()->where('ticket_id', $ticket->id)->orderByDesc('id')->firstOrFail());
        $fresh = app(TenantContext::class)->run($tenant, fn () => AiRun::query()->findOrFail($run->id));
        expect($message->author)->toBe(MessageAuthor::Ai)->and($message->user_id)->toBe($agent->id)->and($message->body)->toBe('Tienes 30 días para devolverlo.

Fuente: Política de devoluciones')
            ->and($fresh->status)->toBe(AiRunStatus::Approved)->and($fresh->message_id)->toBe($message->id)->and($fresh->reviewed_by)->toBe($agent->id)->and($fresh->edited)->toBeFalse();
        $conversation = widgetGet($this, 'conversation', $token)->assertOk()->json('messages');
        expect(end($conversation))->toMatchArray(['author' => 'agent', 'agent' => 'Asistente virtual', 'body' => 'Tienes 30 días para devolverlo.

Fuente: Política de devoluciones']);
        $audit = AuditLog::query()->where('event', 'ai.draft_approved')->sole();
        expect($audit->context)->toEqual(['run_id' => $run->id, 'ticket_id' => $ticket->id, 'edited' => false]);
    });

    it('si la empresa no quiere presentarse como asistente virtual, el cliente no ve ese nombre', function (): void {
        $tenant = aiTenant(['identify_as_ai' => false]);
        $token = widgetStart($this, $tenant);
        $ticket = widgetTicketOf($tenant, $token);
        $run = draftedRun($tenant, $ticket);

        approveDraft($this, ticketStaff($tenant), $ticket, $run)->assertSessionHasNoErrors();

        $conversation = widgetGet($this, 'conversation', $token)->json('messages');
        expect(end($conversation)['agent'])->toBeNull()->and(end($conversation)['author'])->toBe('agent');
    });

    it('editarlo lo convierte en un mensaje de la persona y queda anotado', function (): void {
        $tenant = aiTenant();
        $ticket = makeTicket($tenant);
        $run = draftedRun($tenant, $ticket);
        $agent = ticketStaff($tenant);

        approveDraft($this, $agent, $ticket, $run, ['body' => "Tienes 15 días,\npero hablemos."])->assertSessionHasNoErrors();

        $message = app(TenantContext::class)->run($tenant, fn () => TicketMessage::query()->where('ticket_id', $ticket->id)->orderByDesc('id')->firstOrFail());
        $fresh = app(TenantContext::class)->run($tenant, fn () => AiRun::query()->findOrFail($run->id));
        expect($message->author)->toBe(MessageAuthor::Agent)->and($message->body)->toBe("Tienes 15 días,\npero hablemos.")->and($fresh->edited)->toBeTrue()->and($fresh->status)->toBe(AiRunStatus::Approved);
    });

    it('enviar el borrador con el mismo texto (solo cambian los espacios) no cuenta como editado', function (): void {
        $tenant = aiTenant();
        $ticket = makeTicket($tenant);
        $run = draftedRun($tenant, $ticket);

        approveDraft($this, ticketStaff($tenant), $ticket, $run, ['body' => "  Tienes 30 días\npara devolverlo.  "])->assertSessionHasNoErrors();

        $message = app(TenantContext::class)->run($tenant, fn () => TicketMessage::query()->where('ticket_id', $ticket->id)->orderByDesc('id')->firstOrFail());
        expect($message->author)->toBe(MessageAuthor::Ai);
    });

    it('a un cliente con correo le llega por correo como a cualquier respuesta del equipo', function (): void {
        $tenant = aiTenant();
        $ticket = mailTicket($tenant);
        $run = draftedRun($tenant, $ticket);
        Queue::fake();

        approveDraft($this, ticketStaff($tenant), $ticket, $run)->assertSessionHasNoErrors();

        $rows = mailRows($tenant);
        expect($rows)->toHaveCount(1)->and($rows[0]->kind->value)->toBe('reply')->and($rows[0]->ticket_id)->toBe($ticket->id);
    });

    it('un borrador se envía una sola vez', function (): void {
        $tenant = aiTenant();
        $ticket = makeTicket($tenant);
        $run = draftedRun($tenant, $ticket);
        $agent = ticketStaff($tenant);
        $before = messageCountOf($ticket);

        approveDraft($this, $agent, $ticket, $run)->assertSessionHasNoErrors();
        approveDraft($this, $agent, $ticket, $run)->assertSessionHasErrors('draft');

        expect(messageCountOf($ticket))->toBe($before + 1);
    });

    it('un texto demasiado largo se rechaza y el borrador sigue pendiente', function (): void {
        $tenant = aiTenant();
        $ticket = makeTicket($tenant);
        $run = draftedRun($tenant, $ticket);
        $before = messageCountOf($ticket);

        approveDraft($this, ticketStaff($tenant), $ticket, $run, ['body' => str_repeat('a', 10001)])->assertSessionHasErrors('body');

        expect(messageCountOf($ticket))->toBe($before)->and(app(TenantContext::class)->run($tenant, fn () => AiRun::query()->findOrFail($run->id))->status)->toBe(AiRunStatus::Drafted);
    });

    it('un texto en blanco llega como «sin cambios» (el servidor recorta y vacía los espacios) y se envía el borrador original, nunca un mensaje vacío', function (): void {
        $tenant = aiTenant();
        $ticket = makeTicket($tenant);
        $run = draftedRun($tenant, $ticket);

        approveDraft($this, ticketStaff($tenant), $ticket, $run, ['body' => '   '])->assertSessionHasNoErrors();

        $message = app(TenantContext::class)->run($tenant, fn () => TicketMessage::query()->where('ticket_id', $ticket->id)->orderByDesc('id')->firstOrFail());
        expect($message->body)->toBe('Tienes 30 días para devolverlo.')->and($message->author)->toBe(MessageAuthor::Ai);
    });

    it('en un ticket cerrado no se envía y el borrador queda como estaba', function (): void {
        $tenant = aiTenant();
        $ticket = makeTicket($tenant, ['status' => TicketStatus::Closed]);
        $run = draftedRun($tenant, $ticket);
        $before = messageCountOf($ticket);

        approveDraft($this, ticketStaff($tenant), $ticket, $run)->assertSessionHasErrors('body');

        expect(messageCountOf($ticket))->toBe($before)->and(app(TenantContext::class)->run($tenant, fn () => AiRun::query()->findOrFail($run->id))->status)->toBe(AiRunStatus::Drafted);
    });

    it('un observador no puede aprobar ni descartar', function (): void {
        $tenant = aiTenant();
        $ticket = makeTicket($tenant);
        $run = draftedRun($tenant, $ticket);
        $observer = ticketStaff($tenant, Role::Observer);

        approveDraft($this, $observer, $ticket, $run)->assertForbidden();
        $this->actingAs($observer)->post("/tickets/{$ticket->id}/ai/{$run->id}/discard")->assertForbidden();

        expect(app(TenantContext::class)->run($tenant, fn () => AiRun::query()->findOrFail($run->id))->status)->toBe(AiRunStatus::Drafted);
    });

    it('el borrador de otra empresa, o de otro ticket, da 404', function (): void {
        $mine = aiTenant();
        $other = aiTenant();
        $ticket = makeTicket($mine);
        $otherTicket = makeTicket($other);
        $foreignRun = draftedRun($other, $otherTicket);
        $siblingRun = draftedRun($mine, makeTicket($mine));
        $agent = ticketStaff($mine);

        approveDraft($this, $agent, $ticket, $foreignRun)->assertNotFound();
        approveDraft($this, $agent, $otherTicket, $foreignRun)->assertNotFound();
        approveDraft($this, $agent, $ticket, $siblingRun)->assertNotFound();
        $this->actingAs($agent)->post("/tickets/{$ticket->id}/ai/{$foreignRun->id}/discard")->assertNotFound();
    });
});

describe('descartar un borrador', function (): void {
    it('no envía nada, queda anotado quién lo descartó y no se puede aprobar después', function (): void {
        $tenant = aiTenant();
        $ticket = makeTicket($tenant);
        $run = draftedRun($tenant, $ticket);
        $agent = ticketStaff($tenant);
        $before = messageCountOf($ticket);

        $this->actingAs($agent)->post("/tickets/{$ticket->id}/ai/{$run->id}/discard")->assertSessionHasNoErrors();
        approveDraft($this, $agent, $ticket, $run)->assertSessionHasErrors('draft');

        $fresh = app(TenantContext::class)->run($tenant, fn () => AiRun::query()->findOrFail($run->id));
        expect(messageCountOf($ticket))->toBe($before)->and($fresh->status)->toBe(AiRunStatus::Discarded)->and($fresh->reviewed_by)->toBe($agent->id)
            ->and(AuditLog::query()->where('event', 'ai.draft_discarded')->count())->toBe(1);
    });
});

describe('lo que ve el equipo en el ticket', function (): void {
    function ticketAiProp(object $test, $user, Ticket $ticket): mixed
    {
        $page = $test->actingAs($user)->get("/tickets/{$ticket->id}")->assertOk()->viewData('page');

        return data_get($page['props'], 'selected.ai');
    }

    it('quien puede responder ve el borrador con sus fuentes, y un observador no ve nada de la IA', function (): void {
        $tenant = aiTenant();
        $ticket = makeTicket($tenant);
        $run = draftedRun($tenant, $ticket);

        $panel = ticketAiProp($this, ticketStaff($tenant), $ticket);

        expect($panel)->toMatchArray(['id' => $run->id, 'status' => 'drafted', 'body' => 'Tienes 30 días para devolverlo.'])
            ->and($panel['sources'])->toBe([['kind' => 'article', 'title' => 'Política de devoluciones']])
            ->and(ticketAiProp($this, ticketStaff($tenant, Role::Observer), $ticket))->toBeNull();
    });

    it('un borrador deja de mostrarse cuando alguien ya respondió después', function (): void {
        $tenant = aiTenant();
        $ticket = makeTicket($tenant);
        draftedRun($tenant, $ticket);
        conversationMessage($tenant, $ticket, MessageAuthor::Agent, 'Ya le respondí yo.');

        $panel = ticketAiProp($this, ticketStaff($tenant), $ticket);

        expect($panel['status'])->toBe('superseded')->and($panel['body'])->toBeNull()->and($panel['sources'])->toBe([]);
    });

    it('sin intervención de la IA no hay panel, y si no pudo responder se dice por qué sin mostrar nada más', function (): void {
        $tenant = aiTenant();
        $ticket = makeTicket($tenant);
        expect(ticketAiProp($this, ticketStaff($tenant), $ticket))->toBeNull();

        $message = conversationMessage($tenant, $ticket, MessageAuthor::Customer, 'Hola');
        app(TenantContext::class)->run($tenant, function () use ($ticket, $message): void {
            $run = new AiRun;
            $run->forceFill(['ticket_id' => $ticket->id, 'trigger_message_id' => $message->id, 'status' => AiRunStatus::NoAnswer, 'reason' => AiReason::NoKnowledge])->save();
        });

        $panel = ticketAiProp($this, ticketStaff($tenant), $ticket);
        expect($panel['status'])->toBe('no_answer')->and($panel['reason_label'])->toBe('No encontró en la base de conocimiento nada parecido a la pregunta.')->and($panel['body'])->toBeNull();
    });

    it('en la conversación del equipo, lo que envió la IA se distingue y dice quién lo aprobó', function (): void {
        $tenant = aiTenant();
        $ticket = makeTicket($tenant);
        $run = draftedRun($tenant, $ticket);
        $agent = ticketStaff($tenant);
        approveDraft($this, $agent, $ticket, $run)->assertSessionHasNoErrors();

        $page = $this->actingAs($agent)->get("/tickets/{$ticket->id}")->viewData('page');
        $thread = data_get($page['props'], 'selected.thread');
        $last = end($thread);

        expect($last)->toMatchArray(['author' => 'ai', 'author_name' => 'Asistente virtual', 'approved_by' => $agent->name]);
    });
});
