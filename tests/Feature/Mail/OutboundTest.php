<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Enums\Role;
use App\Jobs\SendTicketEmail;
use App\Mail\MailKind;
use App\Mail\MailStatus;
use App\Mail\Outbox;
use App\Mail\Threads;
use App\Mail\TicketEmail;
use App\Models\Customer;
use App\Models\TenantBranding;
use App\Tenancy\TenantContext;
use App\Tickets\MessageAuthor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Images;

beforeEach(function (): void {
    Mail::fake();
    config(['mail.from.address' => 'soporte@tikarochat.test']);
});

describe('cuando el equipo responde', function (): void {
    it('el cliente que dejó su correo recibe un correo con la respuesta, desde la empresa y con su hilo', function (): void {
        $tenant = widgetTenant();
        $tenant->forceFill(['name' => 'Tienda Ejemplo'])->save();
        $ticket = mailTicket($tenant);

        sendReply($this, ticketStaff($tenant), $ticket, ['body' => "Ya enviamos tu pedido.\nLlega el viernes."])->assertRedirect();

        Mail::assertSent(TicketEmail::class, function (TicketEmail $mail) use ($tenant, $ticket): bool {
            $token = threadToken($tenant, $ticket);
            $mail->assertTo('marisol@cliente-ejemplo.com');
            $mail->assertSeeInHtml('Ya enviamos tu pedido.');
            $mail->assertSeeInText('Llega el viernes.');

            return $mail->hasFrom('soporte@tikarochat.test', 'Tienda Ejemplo')
                && $mail->hasReplyTo('soporte+t-'.$token.'@tikarochat.test')
                && $mail->kind === MailKind::Reply
                && str_starts_with($mail->messageId, 't-'.$token.'.')
                && str_ends_with($mail->messageId, '@tikarochat.test')
                && $mail->hasSubject('[Ticket #'.$ticket->number.'] '.$ticket->subject);
        });
        $rows = mailRows($tenant);
        expect($rows)->toHaveCount(1)->and($rows[0]->status)->toBe(MailStatus::Sent)->and($rows[0]->kind)->toBe(MailKind::Reply);
    });

    it('sin correo del cliente no sale nada', function (): void {
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant, null);

        sendReply($this, ticketStaff($tenant), $ticket, ['body' => 'Hola'])->assertRedirect();

        Mail::assertNothingSent();
        expect(mailRows($tenant))->toBe([]);
    });

    it('una nota interna nunca sale por correo', function (): void {
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant);

        $this->actingAs(ticketStaff($tenant))->post("/tickets/{$ticket->id}/notes", ['body' => 'NOTA-SECRETA'])->assertRedirect();

        Mail::assertNothingSent();
    });

    it('un mensaje que solo trae archivos no genera correo vacío', function (): void {
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant);
        $message = app(TenantContext::class)->run($tenant, fn () => $ticket->messages()->firstOrFail());
        $message->forceFill(['author' => MessageAuthor::Agent, 'body' => ''])->save();

        app(TenantContext::class)->run($tenant, fn () => app(Outbox::class)->reply($message));

        Mail::assertNothingSent();
    });

    it('pedir dos veces el correo del mismo mensaje no lo envía dos veces', function (): void {
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant);
        sendReply($this, ticketStaff($tenant), $ticket, ['body' => 'Una vez'])->assertRedirect();
        $message = app(TenantContext::class)->run($tenant, fn () => $ticket->messages()->latest('id')->firstOrFail());

        app(TenantContext::class)->run($tenant, fn () => app(Outbox::class)->reply($message));

        Mail::assertSent(TicketEmail::class, 1);
        expect(mailRows($tenant))->toHaveCount(1);
    });

    it('cada ticket tiene un solo hilo, con un token distinto al de otro ticket y al de otra empresa', function (): void {
        $first = widgetTenant();
        $second = widgetTenant();
        $a = mailTicket($first);
        $b = mailTicket($first, 'otro@cliente.com');
        $c = mailTicket($second);

        expect(threadToken($first, $a))->toBe(threadToken($first, $a))
            ->and(threadToken($first, $a))->not->toBe(threadToken($first, $b))
            ->and(threadToken($first, $a))->toMatch('/\A'.$first->id.'-[0-9a-f]{32}\z/')
            ->and(threadToken($second, $c))->toMatch('/\A'.$second->id.'-[0-9a-f]{32}\z/');
    });

    it('el token se guarda cifrado y con su huella, nunca en claro', function (): void {
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant);
        $token = threadToken($tenant, $ticket);

        $raw = DB::table('mail_threads')->where('ticket_id', $ticket->id)->first();

        expect($raw->token)->not->toContain($token)->and($raw->token_hash)->toBe(hash('sha256', $token));
    });
});

describe('los mensajes que llevan texto de una persona', function (): void {
    it('un nombre o asunto con saltos de línea no rompe las cabeceras', function (): void {
        $tenant = widgetTenant();
        $tenant->forceFill(['name' => "Tienda\r\nBcc: victima@otro.com"])->save();
        $ticket = mailTicket($tenant, 'marisol@cliente-ejemplo.com', "Marisol\r\nBcc: otra@persona.com");
        $ticket->forceFill(['subject' => "Hola\r\nBcc: tercera@persona.com"])->save();

        sendReply($this, ticketStaff($tenant), $ticket, ['body' => 'Respuesta'])->assertRedirect();

        Mail::assertSent(TicketEmail::class, function (TicketEmail $mail): bool {
            $envelope = $mail->envelope();
            $subject = (string) $envelope->subject;

            return ! str_contains($subject, "\n") && ! str_contains($subject, "\r")
                && ! str_contains((string) $envelope->from?->name, "\n")
                && $envelope->cc === [] && $envelope->bcc === []
                && ! str_contains($mail->greetingName(), "\n");
        });
    });

    it('el texto del mensaje se escapa en el HTML y no abre etiquetas', function (): void {
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant);

        sendReply($this, ticketStaff($tenant), $ticket, ['body' => '<script>alert(1)</script> <img src=x onerror=alert(2)>'])->assertRedirect();

        Mail::assertSent(TicketEmail::class, function (TicketEmail $mail): bool {
            $html = $mail->render();

            return ! str_contains($html, '<script>alert(1)</script>') && ! str_contains($html, '<img src=x') && str_contains($html, '&lt;script&gt;');
        });
    });

    it('al visitante del widget sin nombre se le saluda sin su nombre provisional', function (): void {
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant, 'marisol@cliente-ejemplo.com', 'Visitante M9GC');

        sendReply($this, ticketStaff($tenant), $ticket, ['body' => 'Hola'])->assertRedirect();

        Mail::assertSent(TicketEmail::class, fn (TicketEmail $mail): bool => $mail->greetingName() === '' && ! str_contains($mail->render(), 'M9GC'));
    });
});

describe('la marca en el correo', function (): void {
    it('con la paleta por defecto usa el naranja de fábrica con su texto oscuro, todo en hexadecimal', function (): void {
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant);

        sendReply($this, ticketStaff($tenant), $ticket, ['body' => 'Hola'])->assertRedirect();

        Mail::assertSent(TicketEmail::class, function (TicketEmail $mail): bool {
            $html = $mail->render();

            return str_contains($html, 'background-color:#F65F1E') && str_contains($html, 'color:#1D1208')
                && ! str_contains($html, 'var(') && ! str_contains($html, 'color-mix')
                && str_contains($html, '<meta name="color-scheme" content="light">')
                && str_contains($html, 'width="600"');
        });
    });

    it('con la paleta de la empresa usa su primario y su texto calculado, no los de fábrica', function (): void {
        $tenant = widgetTenant();
        brandPalette($tenant, ['primary' => '#2164DA']);
        $ticket = mailTicket($tenant);
        $expected = app(TenantContext::class)->run($tenant, fn () => TenantBranding::query()->sole()->foregrounds['primary']);

        sendReply($this, ticketStaff($tenant), $ticket, ['body' => 'Hola'])->assertRedirect();

        Mail::assertSent(TicketEmail::class, function (TicketEmail $mail) use ($expected): bool {
            $html = $mail->render();

            return str_contains($html, 'background-color:#2164DA') && str_contains($html, 'color:'.$expected)
                && ! str_contains($html, '#F65F1E');
        });
    });

    it('es el mismo color que usa el widget para esa empresa', function (): void {
        $tenant = widgetTenant();
        brandPalette($tenant, ['primary' => '#2164DA']);
        $branding = app(TenantContext::class)->run($tenant, fn () => TenantBranding::query()->sole());

        expect($branding->widgetVariables()['--color-primary'])->toBe('#2164DA')
            ->and($branding->widgetVariables()['--color-primary-foreground'])->toBe($branding->foregrounds['primary']);
    });

    it('con logo y el widget encendido lo incluye con su texto alternativo; sin logo muestra el nombre', function (): void {
        Storage::fake('local');
        $tenant = widgetTenant();
        $tenant->forceFill(['name' => 'Tienda Ejemplo'])->save();
        $ticket = mailTicket($tenant);

        sendReply($this, ticketStaff($tenant), $ticket, ['body' => 'Una'])->assertRedirect();
        Mail::assertSent(TicketEmail::class, fn (TicketEmail $mail): bool => str_contains($mail->render(), '<h1') && ! str_contains($mail->render(), '<img'));

        $this->actingAs(widgetAdmin($tenant))->post('/settings/branding/logo', ['logo' => Images::upload('logo.png', Images::png())])->assertSessionHasNoErrors();
        sendReply($this, ticketStaff($tenant), $ticket, ['body' => 'Dos'])->assertRedirect();

        Mail::assertSent(TicketEmail::class, fn (TicketEmail $mail): bool => str_contains($mail->render(), '<img src="'.url('/widget/logo?key='.$tenant->widget_key.'&amp;v=')) && str_contains($mail->render(), 'alt="Logo de Tienda Ejemplo"'));
    });

    it('sin el widget encendido no hay dirección pública para el logo y se muestra el nombre', function (): void {
        Storage::fake('local');
        $tenant = widgetTenant(enabled: false);
        $this->actingAs(widgetAdmin($tenant))->post('/settings/branding/logo', ['logo' => Images::upload('logo.png', Images::png())])->assertSessionHasNoErrors();
        $ticket = mailTicket($tenant);

        sendReply($this, ticketStaff($tenant), $ticket, ['body' => 'Hola'])->assertRedirect();

        Mail::assertSent(TicketEmail::class, fn (TicketEmail $mail): bool => ! str_contains($mail->render(), '<img'));
    });
});

describe('la confirmación de ticket recibido', function (): void {
    it('el visitante que deja su correo al escribir recibe una confirmación con su número de ticket, una sola vez', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant, ['email' => 'Marisol@Cliente-Ejemplo.com', 'name' => 'Marisol']);

        widgetSend($this, $tenant, ['body' => 'Otro mensaje'], $token)->assertOk();

        $ticket = widgetTicketOf($tenant, $token);
        Mail::assertSent(TicketEmail::class, 1);
        Mail::assertSent(TicketEmail::class, function (TicketEmail $mail) use ($ticket): bool {
            $mail->assertTo('marisol@cliente-ejemplo.com');
            $mail->assertSeeInText('#'.$ticket->number);

            return $mail->kind === MailKind::Received;
        });
    });

    it('si deja el correo después de escribir, la confirmación sale entonces', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant);
        Mail::assertNothingSent();

        $this->postJson('/widget/api/contact', ['email' => 'marisol@cliente.com'], ['Authorization' => 'Bearer '.$token])->assertOk();
        $this->postJson('/widget/api/contact', ['email' => 'otro@cliente.com'], ['Authorization' => 'Bearer '.$token])->assertOk();

        Mail::assertSent(TicketEmail::class, 1);
        Mail::assertSent(TicketEmail::class, fn (TicketEmail $mail): bool => $mail->hasTo('marisol@cliente.com'));
    });

    it('sin correo no se confirma nada', function (): void {
        $tenant = widgetTenant();

        widgetStart($this, $tenant);

        Mail::assertNothingSent();
    });

    it('a una misma dirección se le confirman pocos tickets por día, para no usar el chat contra terceros', function (): void {
        config(['mailbox.outbound.received_per_address_per_day' => 2]);
        RateLimiter::clear('x');
        $tenants = [widgetTenant(), widgetTenant(), widgetTenant()];

        foreach ($tenants as $tenant) {
            $ticket = mailTicket($tenant, 'victima@otra-empresa.com');
            app(TenantContext::class)->run($tenant, fn () => app(Outbox::class)->received($ticket));
        }

        Mail::assertSent(TicketEmail::class, 2);
    });

    it('la confirmación no lleva el texto de lo que escribió el visitante', function (): void {
        $tenant = widgetTenant();
        widgetStart($this, $tenant, ['email' => 'marisol@cliente.com', 'body' => 'TEXTO-PRIVADO-DEL-VISITANTE']);

        Mail::assertSent(TicketEmail::class, fn (TicketEmail $mail): bool => ! str_contains($mail->render(), 'TEXTO-PRIVADO') && ! str_contains($mail->render(), 'TEXTO-PRIVADO'));
    });
});

describe('el envío por la cola', function (): void {
    it('agotados los intentos queda fallido sin guardar el texto del error', function (): void {
        Queue::fake();
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant);
        $message = app(TenantContext::class)->run($tenant, fn () => $ticket->messages()->firstOrFail());
        $message->forceFill(['author' => MessageAuthor::Agent, 'body' => 'Hola'])->save();
        app(TenantContext::class)->run($tenant, fn () => app(Outbox::class)->reply($message));
        $row = mailRows($tenant)[0];

        app(TenantContext::class)->run($tenant, fn () => (new SendTicketEmail($row->id))->failed(new RuntimeException('clave-secreta')));

        $after = mailRows($tenant)[0];
        expect($after->status)->toBe(MailStatus::Failed)->and($after->reason)->toBe('send_failed');
        expect(json_encode(DB::table('mail_messages')->get()))->not->toContain('clave-secreta');
    });

    it('un correo ya enviado no se envía otra vez si el trabajo se repite', function (): void {
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant);
        sendReply($this, ticketStaff($tenant), $ticket, ['body' => 'Hola'])->assertRedirect();
        $row = mailRows($tenant)[0];

        app(TenantContext::class)->run($tenant, fn () => (new SendTicketEmail($row->id))->handle(app(Threads::class)));

        Mail::assertSent(TicketEmail::class, 1);
    });

    it('si el cliente ya no tiene correo, no se envía ni se reintenta', function (): void {
        Mail::fake();
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant);
        $message = app(TenantContext::class)->run($tenant, fn () => $ticket->messages()->firstOrFail());
        $message->forceFill(['author' => MessageAuthor::Agent, 'body' => 'Hola'])->save();
        Queue::fake();
        app(TenantContext::class)->run($tenant, fn () => app(Outbox::class)->reply($message));
        app(TenantContext::class)->run($tenant, fn () => Customer::query()->update(['email' => null]));
        $row = mailRows($tenant)[0];

        app(TenantContext::class)->run($tenant, fn () => (new SendTicketEmail($row->id))->handle(app(Threads::class)));

        Mail::assertNothingSent();
        expect(mailRows($tenant)[0]->status)->toBe(MailStatus::Failed)->and(mailRows($tenant)[0]->reason)->toBe('no_recipient');
    });
});

describe('Threads', function (): void {
    it('encuentra los tokens en las direcciones con «+t-» y en los identificadores de correo', function (): void {
        $a = '7-'.str_repeat('a1', 16);
        $b = '12-'.str_repeat('0f', 16);

        expect(Threads::tokensIn("Soporte <soporte+t-{$a}@tikarochat.test>"))->toBe([['tenant_id' => 7, 'token' => str_repeat('a1', 16)]])
            ->and(Threads::tokensIn("<t-{$b}.abc123@tikarochat.test> <t-{$a}.zzz@tikarochat.test>"))->toHaveCount(2)
            ->and(Threads::tokensIn("SOPORTE+T-{$a}@TIKAROCHAT.TEST"))->toHaveCount(1)
            ->and(Threads::tokensIn("soporte+t-{$a}@a.com, soporte+t-{$a}@b.com"))->toHaveCount(1);
    });

    it('ignora lo que no tiene la forma exacta', function (string $text): void {
        expect(Threads::tokensIn($text))->toBe([]);
    })->with([
        'sin mas' => ['soporte@tikarochat.test'],
        'token corto' => ['soporte+t-7-abc@tikarochat.test'],
        'token largo' => ['soporte+t-7-'.str_repeat('a', 33).'@tikarochat.test'],
        'sin empresa' => ['soporte+t-'.str_repeat('a', 32).'@tikarochat.test'],
        'empresa no numérica' => ['soporte+t-x-'.str_repeat('a', 32).'@tikarochat.test'],
        'mayusculas no hex' => ['soporte+t-7-'.str_repeat('g', 32).'@tikarochat.test'],
        'sin separador' => ['soporte t-7-'.str_repeat('a', 32).'@tikarochat.test'],
    ]);

    it('el token de otra empresa no encuentra el hilo en esta', function (): void {
        $mine = widgetTenant();
        $theirs = widgetTenant();
        $ticket = mailTicket($theirs);
        $token = threadToken($theirs, $ticket);
        [, $hex] = explode('-', $token);

        $found = app(Threads::class)->find(['tenant_id' => $mine->id, 'token' => $hex]);

        expect($found)->toBeNull();
    });
});

it('el rol de observador no dispara correos aunque intente responder', function (): void {
    $tenant = widgetTenant();
    $ticket = mailTicket($tenant);

    sendReply($this, ticketStaff($tenant, Role::Observer), $ticket, ['body' => 'No'])->assertForbidden();

    Mail::assertNothingSent();
});
