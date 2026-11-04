<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Attachments\AttachmentStatus;
use App\Events\InboxChanged;
use App\Events\TicketChanged;
use App\Events\WidgetChanged;
use App\Jobs\ScanAttachment;
use App\Mail\Inbound\InboundAttachment;
use App\Mail\Inbound\InboundBody;
use App\Mail\Inbound\InboundMail;
use App\Mail\Inbound\InboundMailProcessor;
use App\Mail\MailStatus;
use App\Models\MailMessage;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Tenancy\TenantContext;
use App\Tickets\MessageAuthor;
use App\Tickets\TicketStatus;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    config(['mail.from.address' => 'soporte@geckochat.test']);
    Queue::fake();
    useTemporaryAttachmentDisk();
});

/**
 * @param  array<string, mixed>  $o
 */
function inbound(array $o = []): InboundMail
{
    $text = $o['text'] ?? 'Gracias, ya me llegó.';

    return new InboundMail(
        handle: new stdClass,
        messageId: $o['id'] ?? '<'.bin2hex(random_bytes(8)).'@cliente.example>',
        from: array_key_exists('from', $o) ? $o['from'] : 'marisol@cliente-ejemplo.com',
        recipients: $o['to'] ?? 'soporte@geckochat.test',
        references: $o['references'] ?? '',
        headers: $o['headers'] ?? [],
        size: $o['size'] ?? 2048,
        body: fn (): InboundBody => new InboundBody($text, $o['html'] ?? '', $o['attachments'] ?? []),
    );
}

/** Un correo que responde al hilo del ticket por la dirección de respuesta. */
function replyTo(Tenant $tenant, Ticket $ticket, array $o = []): InboundMail
{
    return inbound(['to' => 'Soporte <soporte+t-'.threadToken($tenant, $ticket).'@geckochat.test>', ...$o]);
}

function processMail(InboundMail $mail): string
{
    return app(InboundMailProcessor::class)->process($mail);
}

/** @return list<string> */
function bodiesOfTicket(Tenant $tenant, Ticket $ticket): array
{
    return widgetBodies($tenant, $ticket);
}

function rejectionCount(string $reason): int
{
    return (int) DB::table('mail_rejections')->where('reason', $reason)->sum('total');
}

describe('una respuesta del cliente', function (): void {
    it('entra al ticket como mensaje del cliente, mueve la fecha del último mensaje y queda registrada', function (): void {
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant);
        $this->travel(10)->minutes();

        expect(processMail(replyTo($tenant, $ticket, ['text' => 'Gracias, ya me llegó el pedido.'])))->toBe('accepted');

        $message = app(TenantContext::class)->run($tenant, fn () => TicketMessage::query()->where('ticket_id', $ticket->id)->latest('id')->firstOrFail());
        expect($message->author)->toBe(MessageAuthor::Customer)
            ->and($message->user_id)->toBeNull()
            ->and($message->body)->toBe('Gracias, ya me llegó el pedido.')
            ->and(reloaded($ticket)->last_message_at->isAfter($ticket->last_message_at))->toBeTrue();
        $row = mailRows($tenant)[0];
        expect($row->status)->toBe(MailStatus::Accepted)->and($row->ticket_message_id)->toBe($message->id);
    });

    it('llega al mismo ticket por la dirección «+t-» o por las referencias del correo que cita al nuestro', function (): void {
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant);
        $token = threadToken($tenant, $ticket);

        processMail(replyTo($tenant, $ticket, ['text' => 'Por dirección']));
        processMail(inbound(['references' => "<otro@x.com> <t-{$token}.abc123@geckochat.test>", 'text' => 'Por referencias']));

        expect(array_slice(bodiesOfTicket($tenant, $ticket), -2))->toBe(['Por dirección', 'Por referencias']);
    });

    it('el remitente se compara sin distinguir mayúsculas', function (): void {
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant, 'marisol@cliente-ejemplo.com');

        expect(processMail(replyTo($tenant, $ticket, ['from' => 'Marisol@Cliente-Ejemplo.COM'])))->toBe('accepted');
    });

    it('guarda solo lo que escribió: sin la cita del mensaje anterior, sin «>» y sin firma', function (): void {
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant);
        $text = "Perfecto, gracias.\n\nQuedo atenta.\n-- \nMarisol Quintero\n\nEl lun, 2 nov 2026 a las 10:00, Soporte <soporte@geckochat.test> escribió:\n> Ya enviamos tu pedido.\n> Llega el viernes.";

        processMail(replyTo($tenant, $ticket, ['text' => $text]));

        expect(array_slice(bodiesOfTicket($tenant, $ticket), -1))->toBe(["Perfecto, gracias.\n\nQuedo atenta."]);
    });

    it('si el correo solo trae HTML lo convierte a texto sin etiquetas ni código', function (): void {
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant);
        $html = '<html><head><style>p{color:red}</style><script>alert(1)</script></head><body><p>Hola <b>equipo</b></p><div>Segunda &amp; l&iacute;nea</div><img src=x onerror=alert(2)></body></html>';

        processMail(replyTo($tenant, $ticket, ['text' => '', 'html' => $html]));

        $body = array_slice(bodiesOfTicket($tenant, $ticket), -1)[0];
        expect($body)->toBe("Hola equipo\nSegunda & línea")->and($body)->not->toContain('<')->not->toContain('alert');
    });

    it('nunca guarda HTML aunque el texto lo traiga: se muestra como texto', function (): void {
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant);

        processMail(replyTo($tenant, $ticket, ['text' => '<script>alert(1)</script> **negrita**']));

        expect(array_slice(bodiesOfTicket($tenant, $ticket), -1))->toBe(['<script>alert(1)</script> **negrita**']);
    });

    it('quita los caracteres de control y recorta al tope de un mensaje', function (): void {
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant);

        processMail(replyTo($tenant, $ticket, ['text' => "Hola\x00\x07 mundo ".str_repeat('a', 20000)]));

        $body = array_slice(bodiesOfTicket($tenant, $ticket), -1)[0];
        expect(mb_strlen($body))->toBe(10000)->and($body)->not->toContain("\x00")->and(str_starts_with($body, 'Hola mundo '))->toBeTrue();
    });

    it('reabre un ticket resuelto o en espera del cliente, pero un ticket cerrado no recibe nada', function (): void {
        $tenant = widgetTenant();
        $resolved = mailTicket($tenant, 'a@cliente.com');
        $waiting = mailTicket($tenant, 'b@cliente.com');
        $closed = mailTicket($tenant, 'c@cliente.com');
        $resolved->forceFill(['status' => TicketStatus::Resolved, 'resolved_at' => now()])->save();
        $waiting->forceFill(['status' => TicketStatus::WaitingCustomer])->save();
        $closed->forceFill(['status' => TicketStatus::Closed])->save();

        expect(processMail(replyTo($tenant, $resolved, ['from' => 'a@cliente.com'])))->toBe('accepted')
            ->and(processMail(replyTo($tenant, $waiting, ['from' => 'b@cliente.com'])))->toBe('accepted')
            ->and(processMail(replyTo($tenant, $closed, ['from' => 'c@cliente.com'])))->toBe('closed');

        expect(reloaded($resolved)->status)->toBe(TicketStatus::Open)->and(reloaded($resolved)->resolved_at)->toBeNull()
            ->and(reloaded($waiting)->status)->toBe(TicketStatus::Open)
            ->and(reloaded($closed)->status)->toBe(TicketStatus::Closed)
            ->and(bodiesOfTicket($tenant, $closed))->toHaveCount(1);
    });

    it('avisa al equipo (ticket y bandeja) y, si nació en el widget, al visitante', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant, ['email' => 'marisol@cliente-ejemplo.com']);
        $ticket = widgetTicketOf($tenant, $token);
        Queue::fake();

        processMail(replyTo($tenant, $ticket));

        $events = Queue::pushed(BroadcastEvent::class)->map(fn (BroadcastEvent $job): object => $job->event);
        expect($events->filter(fn (object $e): bool => $e instanceof TicketChanged)->count())->toBe(1)
            ->and($events->filter(fn (object $e): bool => $e instanceof InboxChanged)->count())->toBe(1)
            ->and($events->filter(fn (object $e): bool => $e instanceof WidgetChanged)->count())->toBe(1);
    });

    it('el mismo correo dos veces entra una sola vez', function (): void {
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant);
        $mail = replyTo($tenant, $ticket, ['id' => '<repetido@cliente.example>']);

        expect(processMail($mail))->toBe('accepted')->and(processMail($mail))->toBe('duplicate');

        expect(bodiesOfTicket($tenant, $ticket))->toHaveCount(2);
    });

    it('el contexto de empresa que fijó la búsqueda no se queda para el siguiente correo', function (): void {
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant);

        processMail(replyTo($tenant, $ticket));

        expect(app(TenantContext::class)->current())->toBeNull();
    });
});

describe('lo que no llega a ningún ticket', function (): void {
    it('un correo sin token, con token falso o de una empresa que no existe se descarta y solo se cuenta', function (string $to): void {
        $tenant = widgetTenant();
        mailTicket($tenant);
        $before = DB::table('mail_messages')->count();

        $outcome = processMail(inbound(['to' => $to]));

        expect($outcome)->toBe('no_thread')
            ->and(DB::table('mail_messages')->count())->toBe($before)
            ->and(rejectionCount('no_thread'))->toBe(1);
    })->with([
        'sin token' => ['soporte@geckochat.test'],
        'token inventado' => ['soporte+t-1-'.str_repeat('a', 32).'@geckochat.test'],
        'empresa inexistente' => ['soporte+t-99999999-'.str_repeat('b', 32).'@geckochat.test'],
    ]);

    it('lo contado no guarda remitente ni asunto', function (): void {
        processMail(inbound(['from' => 'alguien@secreto.example', 'to' => 'soporte@geckochat.test']));

        expect(json_encode(DB::table('mail_rejections')->get()))->not->toContain('secreto');
    });

    it('un correo demasiado grande ni se descarga', function (): void {
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant);
        $downloaded = false;
        $mail = new InboundMail(new stdClass, '<grande@x>', 'marisol@cliente-ejemplo.com', 'soporte+t-'.threadToken($tenant, $ticket).'@geckochat.test', '', [], 60 * 1024 * 1024, function () use (&$downloaded): InboundBody {
            $downloaded = true;

            return new InboundBody('x', '');
        });

        expect(processMail($mail))->toBe('too_big')->and($downloaded)->toBeFalse();
    });
});

describe('quién puede escribir en un hilo', function (): void {
    it('saber el token no basta: otro remitente se rechaza y no escribe nada', function (): void {
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant, 'marisol@cliente-ejemplo.com');

        $outcome = processMail(replyTo($tenant, $ticket, ['from' => 'intruso@malo.example', 'text' => 'Soy yo, cambien mi dirección']));

        expect($outcome)->toBe('sender_mismatch')->and(bodiesOfTicket($tenant, $ticket))->toHaveCount(1);
        expect(mailRows($tenant)[0]->status)->toBe(MailStatus::Rejected)->and(mailRows($tenant)[0]->reason)->toBe('sender_mismatch');
    });

    it('un correo sin remitente se rechaza', function (): void {
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant);

        expect(processMail(replyTo($tenant, $ticket, ['from' => null])))->toBe('auto_reply');
    });

    it('un ticket cuyo cliente no dejó correo no recibe respuestas por correo', function (): void {
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant, null);

        expect(processMail(replyTo($tenant, $ticket, ['from' => 'cualquiera@x.com'])))->toBe('sender_mismatch');
    });

    it('un token de una empresa no escribe en otra aunque el cliente tenga el mismo correo en las dos', function (): void {
        $first = widgetTenant();
        $second = widgetTenant();
        $a = mailTicket($first, 'marisol@cliente-ejemplo.com');
        $b = mailTicket($second, 'marisol@cliente-ejemplo.com');

        processMail(replyTo($first, $a, ['text' => 'Solo para la primera']));

        expect(bodiesOfTicket($first, $a))->toHaveCount(2)->and(bodiesOfTicket($second, $b))->toHaveCount(1);
    });

    it('un token con la empresa cambiada no encuentra el hilo de la otra', function (): void {
        $first = widgetTenant();
        $second = widgetTenant();
        $a = mailTicket($first);
        $hex = explode('-', threadToken($first, $a))[1];

        $outcome = processMail(inbound(['to' => "soporte+t-{$second->id}-{$hex}@geckochat.test"]));

        expect($outcome)->toBe('no_thread')->and(bodiesOfTicket($first, $a))->toHaveCount(1);
    });
});

describe('respuestas automáticas y bucles', function (): void {
    it('rechaza lo automático, las listas y los rebotes', function (array $o): void {
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant);

        expect(processMail(replyTo($tenant, $ticket, $o)))->toBe('auto_reply');
        expect(bodiesOfTicket($tenant, $ticket))->toHaveCount(1);
    })->with([
        'auto-submitted' => [['headers' => ['auto-submitted' => 'auto-replied']]],
        'x-autoreply' => [['headers' => ['x-autoreply' => 'yes']]],
        'precedence bulk' => [['headers' => ['precedence' => 'bulk']]],
        'lista' => [['headers' => ['list-id' => '<lista.example.com>']]],
        'rebote' => [['from' => 'mailer-daemon@cliente.example']],
        'no-reply' => [['from' => 'no-reply@cliente.example']],
    ]);

    it('«Auto-Submitted: no» es un correo normal', function (): void {
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant);

        expect(processMail(replyTo($tenant, $ticket, ['headers' => ['auto-submitted' => 'no']])))->toBe('accepted');
    });

    it('lo que sale de nuestra propia dirección no vuelve a entrar', function (): void {
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant, 'soporte@geckochat.test');

        expect(processMail(replyTo($tenant, $ticket, ['from' => 'soporte@geckochat.test'])))->toBe('own_message');
    });
});

describe('autenticidad del remitente', function (): void {
    it('un SPF, DKIM o DMARC fallido declarado por el servidor se rechaza; uno correcto o sin cabecera se acepta', function (string $results, string $expected): void {
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant);
        $headers = $results === '' ? [] : ['authentication-results' => $results];

        expect(processMail(replyTo($tenant, $ticket, ['headers' => $headers])))->toBe($expected);
    })->with([
        'dkim fail' => ['mx.example.com; dkim=fail header.d=cliente.example', 'auth_failed'],
        'spf fail' => ['mx.example.com; spf=fail smtp.mailfrom=cliente.example', 'auth_failed'],
        'dmarc fail' => ['mx.example.com; dmarc=fail header.from=cliente.example', 'auth_failed'],
        'todo bien' => ['mx.example.com; dkim=pass; spf=pass; dmarc=pass', 'accepted'],
        'neutral' => ['mx.example.com; spf=neutral', 'accepted'],
        'sin cabecera' => ['', 'accepted'],
    ]);
});

describe('vacíos e inundaciones', function (): void {
    it('un correo sin texto propio (solo cita o vacío) se rechaza', function (string $text): void {
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant);

        expect(processMail(replyTo($tenant, $ticket, ['text' => $text])))->toBe('empty');
        expect(bodiesOfTicket($tenant, $ticket))->toHaveCount(1);
    })->with([
        'vacío' => [''],
        'solo espacios' => ["  \n\t "],
        'solo cita' => ["> algo\n> más"],
        'solo la cabecera de la cita' => ["On Mon, Nov 2, 2026, Soporte wrote:\n> Hola"],
    ]);

    it('un ticket acepta pocos correos por hora', function (): void {
        config(['mailbox.inbound.per_ticket_per_hour' => 2]);
        RateLimiter::clear('x');
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant);

        expect(processMail(replyTo($tenant, $ticket)))->toBe('accepted')
            ->and(processMail(replyTo($tenant, $ticket)))->toBe('accepted')
            ->and(processMail(replyTo($tenant, $ticket)))->toBe('rate_limited');
        expect(bodiesOfTicket($tenant, $ticket))->toHaveCount(3);
        $this->travel(61)->minutes();
        expect(processMail(replyTo($tenant, $ticket)))->toBe('accepted');
    });
});

describe('adjuntos', function (): void {
    it('un adjunto válido queda por revisar con el antivirus y no se entrega hasta que salga limpio', function (): void {
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant);

        processMail(replyTo($tenant, $ticket, ['attachments' => [new InboundAttachment('factura.txt', 'Total: 100')]]));

        $attachments = attachmentsOf($tenant);
        expect($attachments)->toHaveCount(1)
            ->and($attachments[0]->original_name)->toBe('factura.txt')
            ->and($attachments[0]->status)->toBe(AttachmentStatus::Pending)
            ->and($attachments[0]->uploaded_by)->toBeNull();
        Queue::assertPushed(ScanAttachment::class, 1);
    });

    it('un archivo peligroso o que finge ser otro se descarta, y el resto del correo entra', function (): void {
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant);

        $outcome = processMail(replyTo($tenant, $ticket, ['text' => 'Adjunto mi comprobante', 'attachments' => [
            new InboundAttachment('virus.exe', "MZ\x90\x00\x03"),
            new InboundAttachment('falso.pdf', 'esto no es un pdf'),
            new InboundAttachment('bueno.txt', 'texto normal'),
        ]]));

        expect($outcome)->toBe('accepted')
            ->and(array_column(attachmentsOf($tenant)->all(), 'original_name'))->toBe(['bueno.txt'])
            ->and(array_slice(bodiesOfTicket($tenant, $ticket), -1))->toBe(['Adjunto mi comprobante']);
        expect(mailRows($tenant)[0]->reason)->toBe('files_rejected');
    });

    it('con más archivos del máximo por mensaje guarda los primeros', function (): void {
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant);
        $files = array_map(fn (int $n): InboundAttachment => new InboundAttachment("archivo{$n}.txt", "contenido {$n}"), range(1, 7));

        processMail(replyTo($tenant, $ticket, ['attachments' => $files]));

        expect(attachmentsOf($tenant))->toHaveCount(5);
    });

    it('un correo solo con archivos buenos entra aunque no tenga texto', function (): void {
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant);

        expect(processMail(replyTo($tenant, $ticket, ['text' => '', 'attachments' => [new InboundAttachment('nota.txt', 'solo el archivo')]])))->toBe('accepted');
    });

    it('un correo vacío con solo archivos malos se rechaza y no deja nada en el disco', function (): void {
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant);

        $outcome = processMail(replyTo($tenant, $ticket, ['text' => '', 'attachments' => [new InboundAttachment('virus.exe', "MZ\x90\x00")]]));

        expect($outcome)->toBe('empty')->and(storedFiles())->toBe([])->and(attachmentsOf($tenant))->toHaveCount(0);
    });

    it('el nombre del archivo no decide dónde se guarda: la ruta la genera el sistema', function (): void {
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant);

        processMail(replyTo($tenant, $ticket, ['attachments' => [new InboundAttachment('../../../etc/passwd.txt', 'contenido de texto normal')]]));

        $attachment = attachmentsOf($tenant)[0];
        expect($attachment->path)->toStartWith("tenants/{$tenant->id}/attachments/")->and($attachment->path)->not->toContain('passwd')
            ->and($attachment->original_name)->not->toContain('/');
    });
});

it('un correo rechazado deja su motivo pero ningún texto del cliente', function (): void {
    $tenant = widgetTenant();
    $ticket = mailTicket($tenant);

    processMail(replyTo($tenant, $ticket, ['from' => 'intruso@malo.example', 'text' => 'TEXTO-DEL-INTRUSO']));

    expect(json_encode(DB::table('mail_messages')->get()))->not->toContain('TEXTO-DEL-INTRUSO')->not->toContain('intruso');
    expect(MailMessage::query()->withoutGlobalScopes()->where('reason', 'sender_mismatch')->count())->toBe(1);
});
