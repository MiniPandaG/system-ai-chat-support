<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Mail\Inbound\ImapMailboxReader;
use App\Mail\Inbound\InboundBody;
use App\Mail\Inbound\InboundMail;
use App\Mail\Inbound\MailboxReader;
use App\Mail\Inbound\NullMailboxReader;
use App\Mail\Inbound\ReplyText;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeMailboxReader;

beforeEach(function (): void {
    config(['mail.from.address' => 'soporte@geckochat.test']);
    Queue::fake();
});

function mailFor(string $id, string $to, string $text = 'Hola', string $from = 'marisol@cliente-ejemplo.com'): InboundMail
{
    return new InboundMail(new stdClass, $id, $from, $to, '', [], 100, fn (): InboundBody => new InboundBody($text, ''));
}

describe('el comando mail:fetch', function (): void {
    it('entrega cada correo a su ticket y lo marca como leído', function (): void {
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant);
        $address = 'soporte+t-'.threadToken($tenant, $ticket).'@geckochat.test';
        $reader = new FakeMailboxReader([mailFor('<a@x>', $address, 'Primero'), mailFor('<b@x>', $address, 'Segundo'), mailFor('<c@x>', 'soporte@geckochat.test', 'Perdido')]);
        $this->app->instance(MailboxReader::class, $reader);

        $this->artisan('mail:fetch')->expectsOutput('Correos leídos: 3 (aceptados 2, rechazados 1).')->assertSuccessful();

        expect(array_slice(widgetBodies($tenant, $ticket), -2))->toBe(['Primero', 'Segundo'])->and($reader->seen)->toBe(['<a@x>', '<b@x>', '<c@x>']);
        $this->artisan('mail:fetch')->expectsOutput('Correos leídos: 0 (aceptados 0, rechazados 0).')->assertSuccessful();
    });

    it('sin buzón configurado avisa y no hace nada', function (): void {
        config(['mailbox.imap.host' => null]);

        expect(app(MailboxReader::class))->toBeInstanceOf(NullMailboxReader::class);
        $this->artisan('mail:fetch')->expectsOutput('No hay buzón configurado (MAILBOX_IMAP_HOST).')->assertSuccessful();
    });

    it('con un servidor configurado usa el lector IMAP', function (): void {
        config(['mailbox.imap.host' => 'imap.geckochat.test', 'mailbox.imap.username' => 'soporte', 'mailbox.imap.password' => 'x']);

        expect(app(MailboxReader::class))->toBeInstanceOf(ImapMailboxReader::class);
    });

    it('un correo que falla se reintenta y, pasado el tope, se descarta con su motivo sin bloquear a los demás', function (): void {
        $tenant = widgetTenant();
        $ticket = mailTicket($tenant);
        $address = 'soporte+t-'.threadToken($tenant, $ticket).'@geckochat.test';
        $broken = new InboundMail(new stdClass, '<roto@x>', 'marisol@cliente-ejemplo.com', $address, '', [], 100, fn (): InboundBody => throw new RuntimeException('clave-secreta del buzón'));
        $reader = new FakeMailboxReader([$broken, mailFor('<ok@x>', $address, 'Sí entra')]);
        $this->app->instance(MailboxReader::class, $reader);
        Cache::flush();

        $this->artisan('mail:fetch')->expectsOutput('Correos leídos: 2 (aceptados 1, rechazados 0).')->assertSuccessful();
        expect($reader->seen)->toBe(['<ok@x>']);

        $this->artisan('mail:fetch')->assertSuccessful();
        $this->artisan('mail:fetch')->expectsOutput('Correos leídos: 1 (aceptados 0, rechazados 1).')->assertSuccessful();

        expect($reader->seen)->toBe(['<ok@x>', '<roto@x>'])->and(array_slice(widgetBodies($tenant, $ticket), -1))->toBe(['Sí entra']);
    });

    it('si el buzón no responde lo dice sin mostrar el texto del error', function (): void {
        $this->app->instance(MailboxReader::class, new class implements MailboxReader
        {
            public function unread(int $limit): array
            {
                throw new RuntimeException('LOGIN fallido para soporte con la clave super-secreta');
            }

            public function markSeen(InboundMail $mail): void {}
        });

        $this->artisan('mail:fetch')->expectsOutput('No se pudo leer el buzón. Se reintentará.')->doesntExpectOutputToContain('super-secreta')->assertSuccessful();
    });
});

describe('el lector IMAP', function (): void {
    it('une las cabeceras continuadas y suma las repetidas', function (): void {
        $headers = ImapMailboxReader::parseHeaders("From: Marisol <marisol@x.com>\r\nReferences: <a@x>\r\n <b@x>\r\n\t<c@x>\r\nDelivered-To: uno@x.com\r\nDelivered-To: dos@x.com\r\nSubject: Hola\r\n");

        expect($headers['references'])->toBe('<a@x> <b@x> <c@x>')
            ->and($headers['delivered-to'])->toBe('uno@x.com dos@x.com')
            ->and($headers['from'])->toBe('Marisol <marisol@x.com>');
    });

    it('fuera de local no lee sin cifrar', function (): void {
        app()->detectEnvironment(fn (): string => 'production');
        $reader = new ImapMailboxReader(['host' => 'imap.geckochat.test', 'port' => 143, 'encryption' => null, 'validate_cert' => true, 'username' => 'soporte', 'password' => 'clave-que-no-debe-salir', 'folder' => 'INBOX']);

        try {
            $reader->unread(10);
            $message = '';
        } catch (RuntimeException $failure) {
            $message = $failure->getMessage();
        }

        expect($message)->toBe('El buzón debe leerse por una conexión cifrada.')->not->toContain('clave-que-no-debe-salir');
    });

    it('sin servidor no intenta conectar', function (): void {
        $reader = new ImapMailboxReader(['host' => null, 'port' => 993, 'encryption' => 'ssl', 'validate_cert' => true, 'username' => null, 'password' => null, 'folder' => 'INBOX']);

        expect(fn () => $reader->unread(10))->toThrow(RuntimeException::class, 'No hay servidor IMAP configurado.');
    });
});

describe('el texto de una respuesta', function (): void {
    it('corta la cita en español, inglés, Outlook y reenvíos', function (string $quote): void {
        expect(ReplyText::from("Mi respuesta\n\n{$quote}\n> Lo anterior", '', 10000))->toBe('Mi respuesta');
    })->with([
        'español' => ['El lunes, 2 de noviembre de 2026, 10:00, Soporte <soporte@x.com> escribió:'],
        'español partido' => ["El lunes, 2 de noviembre de 2026, 10:00, Soporte\n<soporte@x.com> escribió:"],
        'inglés' => ['On Mon, Nov 2, 2026 at 10:00 AM Soporte <soporte@x.com> wrote:'],
        'original' => ['-----Original Message-----'],
        'reenviado' => ['---------- Forwarded message ---------'],
        'outlook' => ['________________________________'],
    ]);

    it('no recorta una frase normal que menciona «escribió»', function (): void {
        expect(ReplyText::from("Mi hermano escribió que llegó tarde.\nGracias", '', 10000))->toBe("Mi hermano escribió que llegó tarde.\nGracias");
    });

    it('prefiere el texto plano y solo usa el HTML si no hay texto', function (): void {
        expect(ReplyText::from('Texto', '<p>Html</p>', 100))->toBe('Texto')->and(ReplyText::from('', '<p>Html</p>', 100))->toBe('Html');
    });

    it('respeta el tope de caracteres y las líneas en blanco de más se reducen', function (): void {
        expect(ReplyText::from("Uno\n\n\n\n\nDos", '', 100))->toBe("Uno\n\nDos")->and(ReplyText::from('abcdef', '', 3))->toBe('abc');
    });
});
