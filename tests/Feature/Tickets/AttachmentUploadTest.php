<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Attachments\AttachmentStatus;
use App\Enums\Role;
use App\Jobs\ScanAttachment;
use App\Models\TicketMessage;
use App\Tenancy\TenantContext;
use App\Tickets\TicketStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Documents;
use Tests\Support\Images;

beforeEach(function (): void {
    useTemporaryAttachmentDisk();
});

describe('adjuntar a una respuesta', function (): void {
    it('guarda el archivo tal cual, a nombre de quien lo subió, y deja el mensaje con su texto', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $ticket = makeTicket($tenant);
        $bytes = Documents::pdf('Contrato');

        sendReply($this, $agent, $ticket, ['body' => 'Te adjunto el contrato.', 'files' => [Documents::upload('Contrato final.pdf', $bytes)]])->assertRedirect()->assertSessionHasNoErrors();

        $attachment = attachmentsOf($tenant)->sole();
        expect(messageCountOf($ticket))->toBe(2)
            ->and($attachment)->tenant_id->toBe($tenant->id)->ticket_id->toBe($ticket->id)->uploaded_by->toBe($agent->id)
            ->original_name->toBe('Contrato final.pdf')->mime->toBe('application/pdf')->size->toBe(strlen($bytes))->sha256->toBe(hash('sha256', $bytes))
            ->and($attachment->path)->toBe("tenants/{$tenant->id}/attachments/{$attachment->id}")
            ->and(Storage::disk($attachment->disk)->get($attachment->path))->toBe($bytes);
    });

    it('el adjunto cuelga del mensaje nuevo, no de otro', function (): void {
        $tenant = ticketTenant();
        $ticket = makeTicket($tenant);

        sendReply($this, ticketStaff($tenant), $ticket, ['body' => 'Mira', 'files' => [Documents::upload('a.pdf', Documents::pdf())]]);

        $message = app(TenantContext::class)->run($tenant, fn () => TicketMessage::query()->where('ticket_id', $ticket->id)->orderByDesc('id')->firstOrFail());
        expect(attachmentsOf($tenant)->sole()->ticket_message_id)->toBe($message->id)->and($message->body)->toBe('Mira');
    });

    it('un mensaje puede ser solo archivos, sin texto', function (): void {
        $tenant = ticketTenant();
        $ticket = makeTicket($tenant);

        sendReply($this, ticketStaff($tenant), $ticket, ['files' => [Documents::upload('foto.png', Images::png())]])->assertRedirect()->assertSessionHasNoErrors();

        expect(messageCountOf($ticket))->toBe(2)->and(attachmentsOf($tenant))->toHaveCount(1);
    });

    it('sin texto ni archivos no hay mensaje', function (): void {
        $tenant = ticketTenant();
        $ticket = makeTicket($tenant);

        $response = sendReply($this, ticketStaff($tenant), $ticket, ['body' => '', 'files' => []]);

        $response->assertSessionHasErrors('body');
        expect(session('errors')?->first('body'))->toBe('Escribe un mensaje o adjunta un archivo.')->and(messageCountOf($ticket))->toBe(1);
    });

    it('admite los ocho formatos de la lista', function (string $name, string $bytes, string $mime): void {
        $tenant = ticketTenant();
        $ticket = makeTicket($tenant);

        sendReply($this, ticketStaff($tenant), $ticket, ['body' => 'x', 'files' => [Documents::upload($name, $bytes)]])->assertSessionHasNoErrors();

        expect(attachmentsOf($tenant)->sole())->original_name->toBe($name)->mime->toBe($mime);
    })->with([
        'png' => ['foto.png', Images::png(), 'image/png'],
        'jpg' => ['foto.jpg', Images::jpeg(), 'image/jpeg'],
        'jpeg' => ['foto.jpeg', Images::jpeg(), 'image/jpeg'],
        'webp' => ['foto.webp', Images::webp(), 'image/webp'],
        'pdf' => ['doc.pdf', Documents::pdf(), 'application/pdf'],
        'txt' => ['notas.txt', "Hola\nmundo\n", 'text/plain'],
        'csv' => ['datos.csv', "a,b\n1,2\n", 'text/csv'],
        'docx' => ['carta.docx', Documents::docx(), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xlsx' => ['tabla.xlsx', Documents::xlsx(), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    ]);

    it('admite hasta 5 archivos y rechaza el sexto', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $ticket = makeTicket($tenant);
        $files = fn (int $count): array => array_map(fn (int $i): UploadedFile => Documents::upload("doc{$i}.pdf", Documents::pdf("n{$i}")), range(1, $count));

        sendReply($this, $agent, $ticket, ['body' => 'cinco', 'files' => $files(5)])->assertSessionHasNoErrors();
        $response = sendReply($this, $agent, $ticket, ['body' => 'seis', 'files' => $files(6)]);

        $response->assertSessionHasErrors('files');
        expect(session('errors')?->first('files'))->toBe('Un mensaje admite hasta 5 archivos adjuntos.')
            ->and(attachmentsOf($tenant))->toHaveCount(5)->and(messageCountOf($ticket))->toBe(2);
    });

    it('el tope por defecto es de 10 MB por archivo y de 5 por mensaje', function (): void {
        expect(config('attachments.max_bytes'))->toBe(10 * 1024 * 1024)->and(config('attachments.max_per_message'))->toBe(5);
    });

    it('acepta un archivo del tamaño exacto del tope y rechaza uno que lo pasa por un byte', function (): void {
        // Se baja el tope a 100 KB para no mover 10 MB en cada prueba; la regla es la misma para cualquier valor.
        config(['attachments.max_bytes' => 100 * 1024]);
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $ticket = makeTicket($tenant);
        // Texto de verdad en líneas de 64 bytes: una sola letra repetida la confunde el detector de tipos con una cinta magnética.
        $line = str_pad('Linea de texto normal de prueba para el limite de tamano', 63, '.').PHP_EOL;
        $exact = str_repeat($line, intdiv(100 * 1024, 64));

        sendReply($this, $agent, $ticket, ['body' => 'justo', 'files' => [Documents::upload('justo.txt', $exact)]])->assertSessionHasNoErrors();
        $response = sendReply($this, $agent, $ticket, ['body' => 'pasado', 'files' => [Documents::upload('pasado.txt', $exact.'a')]]);

        $response->assertSessionHasErrors('files.0');
        expect(attachmentsOf($tenant))->toHaveCount(1)->and(messageCountOf($ticket))->toBe(2);
    });

    it('un archivo vacío se rechaza', function (): void {
        $tenant = ticketTenant();
        $ticket = makeTicket($tenant);

        $response = sendReply($this, ticketStaff($tenant), $ticket, ['body' => 'x', 'files' => [Documents::upload('vacio.txt', '')]]);

        $response->assertSessionHasErrors('files');
        expect(attachmentsOf($tenant))->toHaveCount(0)->and(messageCountOf($ticket))->toBe(1);
    });
});

describe('lo que no se admite', function (): void {
    it('rechaza lo que no es de la lista, por extensión o por contenido, y no guarda nada', function (string $name, string $bytes): void {
        $tenant = ticketTenant();
        $ticket = makeTicket($tenant);

        sendReply($this, ticketStaff($tenant), $ticket, ['body' => 'x', 'files' => [Documents::upload($name, $bytes)]])->assertSessionHasErrors('files');

        expect(attachmentsOf($tenant))->toHaveCount(0)->and(storedFiles())->toBe([])->and(messageCountOf($ticket))->toBe(1);
    })->with([
        'ejecutable de Windows' => ['programa.exe', "MZ\x90\x00\x03\x00\x00\x00 programa"],
        'script de shell' => ['instalar.sh', "#!/bin/sh\nrm -rf /\n"],
        'PHP' => ['puerta.php', '<?php system($_GET["c"]); ?>'],
        'HTML' => ['pagina.html', '<html><script>alert(1)</script></html>'],
        'SVG con script' => ['dibujo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
        'ZIP' => ['paquete.zip', 'PK'."\x03\x04".'contenido'],
        'JavaScript' => ['codigo.js', 'alert(1)'],
        'sin extensión' => ['archivo', 'texto'],
        'GIF' => ['animacion.gif', 'GIF89a'.str_repeat("\0", 20)],
        'extensión doble peligrosa al final' => ['factura.pdf.exe', Documents::pdf()],
        'doc antiguo' => ['carta.doc', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".'contenido'],
    ]);

    it('rechaza un archivo cuyo contenido no es lo que dice su extensión', function (string $name, string $bytes): void {
        $tenant = ticketTenant();
        $ticket = makeTicket($tenant);

        $response = sendReply($this, ticketStaff($tenant), $ticket, ['body' => 'x', 'files' => [Documents::upload($name, $bytes)]]);

        $response->assertSessionHasErrors('files');
        expect(attachmentsOf($tenant))->toHaveCount(0)->and(storedFiles())->toBe([]);
    })->with([
        'ejecutable que dice ser PDF' => ['factura.pdf', "MZ\x90\x00\x03\x00\x00\x00 programa"],
        'HTML que dice ser PNG' => ['foto.png', '<html><script>alert(1)</script></html>'],
        'PDF que dice ser PNG' => ['foto.png', Documents::pdf()],
        'PNG que dice ser JPG' => ['foto.jpg', Images::png()],
        'texto que dice ser PDF' => ['doc.pdf', 'esto no es un pdf'],
        'PDF que dice ser TXT' => ['notas.txt', Documents::pdf()],
        'ZIP que dice ser PDF' => ['doc.pdf', Documents::zip(['a.txt' => 'x'])],
        'binario que dice ser texto' => ['notas.txt', "texto\0con un byte nulo"],
        'binario que dice ser CSV' => ['datos.csv', "a,b\0\n1,2\n"],
    ]);

    it('un documento de Office debe tener su estructura, y no puede llevar macros ni rutas fuera de lugar', function (string $name, string $bytes): void {
        $tenant = ticketTenant();
        $ticket = makeTicket($tenant);

        sendReply($this, ticketStaff($tenant), $ticket, ['body' => 'x', 'files' => [Documents::upload($name, $bytes)]])->assertSessionHasErrors('files');

        expect(attachmentsOf($tenant))->toHaveCount(0)->and(storedFiles())->toBe([]);
    })->with([
        'ZIP cualquiera como docx' => ['carta.docx', Documents::zip(['a.txt' => 'x'])],
        'estructura de docx con nombre de xlsx' => ['tabla.xlsx', Documents::docx()],
        'estructura de xlsx con nombre de docx' => ['carta.docx', Documents::xlsx()],
        'con macros' => ['carta.docx', Documents::zip(['[Content_Types].xml' => '<Types/>', 'word/document.xml' => '<w/>', 'word/vbaProject.bin' => 'macro'])],
        'con ruta que sale de la carpeta' => ['carta.docx', Documents::zip(['[Content_Types].xml' => '<Types/>', 'word/document.xml' => '<w/>', '../evil.txt' => 'x'])],
        'sin tipos de contenido' => ['carta.docx', Documents::zip(['word/document.xml' => '<w/>'])],
        'con demasiadas entradas' => ['carta.docx', Documents::zip(['[Content_Types].xml' => '<Types/>', 'word/document.xml' => '<w/>', ...array_combine(array_map(fn (int $i): string => "word/p{$i}.xml", range(1, 5001)), array_fill(0, 5001, 'x'))])],
    ]);

    it('un archivo malo rechaza el mensaje entero: no se guarda ni el texto ni los archivos buenos, y dice cuál falló', function (): void {
        $tenant = ticketTenant();
        $ticket = makeTicket($tenant);

        $response = sendReply($this, ticketStaff($tenant), $ticket, [
            'body' => 'Te mando dos archivos',
            'files' => [Documents::upload('bueno.pdf', Documents::pdf()), Documents::upload('malo.exe', 'MZ')],
        ]);

        $response->assertSessionHasErrors('files');
        expect(session('errors')?->first('files'))->toStartWith('«malo.exe»: ')
            ->and(attachmentsOf($tenant))->toHaveCount(0)->and(storedFiles())->toBe([])->and(messageCountOf($ticket))->toBe(1);
    });

    it('un archivo que no es un archivo (un texto o una lista) no pasa', function (): void {
        $tenant = ticketTenant();
        $ticket = makeTicket($tenant);

        sendReply($this, ticketStaff($tenant), $ticket, ['body' => 'x', 'files' => ['esto no es un archivo']])->assertSessionHasErrors('files.0');
        sendReply($this, ticketStaff($tenant), $ticket, ['body' => 'x', 'files' => 'tampoco'])->assertSessionHasErrors('files');

        expect(attachmentsOf($tenant))->toHaveCount(0);
    });

    it('un ticket cerrado no admite mensajes ni archivos', function (): void {
        $tenant = ticketTenant();
        $ticket = makeTicket($tenant, ['status' => TicketStatus::Closed]);

        $response = sendReply($this, ticketStaff($tenant), $ticket, ['body' => 'x', 'files' => [Documents::upload('a.pdf', Documents::pdf())]]);

        $response->assertSessionHasErrors('body');
        expect(attachmentsOf($tenant))->toHaveCount(0)->and(storedFiles())->toBe([]);
    });
});

describe('nombres y rutas', function (): void {
    it('el nombre solo se usa para mostrarlo: la ruta la genera el sistema', function (string $given, string $shown): void {
        $tenant = ticketTenant();
        $ticket = makeTicket($tenant);

        sendReply($this, ticketStaff($tenant), $ticket, ['body' => 'x', 'files' => [Documents::upload($given, Documents::pdf())]])->assertSessionHasNoErrors();

        $attachment = attachmentsOf($tenant)->sole();
        expect($attachment->original_name)->toBe($shown)
            ->and($attachment->path)->toMatch('#^tenants/\d+/attachments/[0-9a-z]{26}$#')
            ->and(storedFiles())->toBe([$attachment->path]);
    })->with([
        'recorrido de carpetas' => ['../../etc/passwd.pdf', 'passwd.pdf'],
        'ruta de Windows' => ['C:\\Users\\Ana\\informe.pdf', 'informe.pdf'],
        'caracteres de ruta y de control' => ['in<>for|me:"?*.pdf', 'in for me.pdf'],
        'salto de línea' => ["inf\norme.pdf", 'inf orme.pdf'],
        'solo puntos y espacios' => ['  ...  .pdf', 'archivo.pdf'],
        'extensión en mayúsculas' => ['INFORME.PDF', 'INFORME.pdf'],
        'espacios repetidos' => ['mi    informe   final.pdf', 'mi informe final.pdf'],
    ]);

    it('un nombre larguísimo se recorta y conserva su extensión', function (): void {
        $tenant = ticketTenant();
        $ticket = makeTicket($tenant);

        sendReply($this, ticketStaff($tenant), $ticket, ['body' => 'x', 'files' => [Documents::upload(str_repeat('a', 400).'.pdf', Documents::pdf())]])->assertSessionHasNoErrors();

        expect(attachmentsOf($tenant)->sole()->original_name)->toBe(str_repeat('a', 100).'.pdf');
    });

    it('un nombre con HTML se guarda como texto', function (): void {
        $tenant = ticketTenant();
        $ticket = makeTicket($tenant);

        sendReply($this, ticketStaff($tenant), $ticket, ['body' => 'x', 'files' => [Documents::upload('<img src=x onerror=alert(1)>.pdf', Documents::pdf())]])->assertSessionHasNoErrors();

        expect(attachmentsOf($tenant)->sole()->original_name)->not->toContain('<')->not->toContain('>');
    });

    it('los archivos de cada empresa van a su propia carpeta', function (): void {
        $a = ticketTenant();
        $b = ticketTenant();

        sendReply($this, ticketStaff($a), makeTicket($a), ['body' => 'x', 'files' => [Documents::upload('a.pdf', Documents::pdf('a'))]]);
        sendReply($this, ticketStaff($b), makeTicket($b), ['body' => 'x', 'files' => [Documents::upload('b.pdf', Documents::pdf('b'))]]);

        expect(attachmentsOf($a)->sole()->path)->toStartWith("tenants/{$a->id}/attachments/")
            ->and(attachmentsOf($b)->sole()->path)->toStartWith("tenants/{$b->id}/attachments/");
    });
});

describe('quién puede', function (): void {
    it('matriz de permisos: administrador y agente adjuntan; el observador no', function (Role $role, int $status): void {
        $tenant = ticketTenant();
        $ticket = makeTicket($tenant);

        sendReply($this, ticketStaff($tenant, $role), $ticket, ['body' => 'x', 'files' => [Documents::upload('a.pdf', Documents::pdf())]])->assertStatus($status);

        expect(attachmentsOf($tenant))->toHaveCount($status === 302 ? 1 : 0);
    })->with([
        'administrador' => [Role::Admin, 302],
        'agente' => [Role::Agent, 302],
        'observador' => [Role::Observer, 403],
    ]);

    it('otra empresa recibe 404 y no deja ningún archivo', function (): void {
        $ticket = makeTicket(ticketTenant());
        $intruder = ticketStaff(ticketTenant(), Role::Admin);

        $this->assertOtherTenantGets404($intruder, 'POST', "/tickets/{$ticket->id}/messages", ['body' => 'x', 'files' => [Documents::upload('a.pdf', Documents::pdf())]]);

        expect(storedFiles())->toBe([]);
    });

    it('lo que el cliente intenta fijar a la fuerza se ignora', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $ticket = makeTicket($tenant);

        sendReply($this, $agent, $ticket, [
            'body' => 'x',
            'files' => [Documents::upload('a.pdf', Documents::pdf())],
            'status' => 'clean',
            'path' => '../../etc/passwd',
            'tenant_id' => 999,
            'uploaded_by' => 999,
            'mime' => 'text/html',
        ]);

        $attachment = attachmentsOf($tenant)->sole();
        expect($attachment->uploaded_by)->toBe($agent->id)->and($attachment->tenant_id)->toBe($tenant->id)->and($attachment->mime)->toBe('application/pdf')
            ->and($attachment->path)->not->toContain('passwd');
    });
});

describe('límite por persona', function (): void {
    it('admite 60 archivos por hora por persona y avisa de cuándo reintentar', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $ticket = makeTicket($tenant);
        RateLimiter::clear('attachments:'.$agent->id);
        $five = fn (): array => array_map(fn (int $i): UploadedFile => Documents::upload("d{$i}.pdf", Documents::pdf("n{$i}")), range(1, 5));

        foreach (range(1, 12) as $ignored) {
            sendReply($this, $agent, $ticket, ['body' => 'x', 'files' => $five()])->assertSessionHasNoErrors();
        }
        $response = sendReply($this, $agent, $ticket, ['body' => 'x', 'files' => [Documents::upload('uno-mas.pdf', Documents::pdf())]]);

        $response->assertSessionHasErrors('files');
        expect(session('errors')?->first('files'))->toStartWith('Subiste demasiados archivos.')->and(attachmentsOf($tenant))->toHaveCount(60);
    });

    it('el límite es de cada persona, no de la empresa', function (): void {
        $tenant = ticketTenant();
        $busy = ticketStaff($tenant);
        $other = ticketStaff($tenant);
        $ticket = makeTicket($tenant);
        RateLimiter::clear('attachments:'.$busy->id);
        foreach (range(1, 60) as $ignored) {
            RateLimiter::hit('attachments:'.$busy->id, 3600);
        }

        sendReply($this, $busy, $ticket, ['body' => 'x', 'files' => [Documents::upload('a.pdf', Documents::pdf())]])->assertSessionHasErrors('files');
        sendReply($this, $other, $ticket, ['body' => 'x', 'files' => [Documents::upload('a.pdf', Documents::pdf())]])->assertSessionHasNoErrors();
    });

    it('enviar un mensaje sin archivos no gasta el límite', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $ticket = makeTicket($tenant);
        RateLimiter::clear('attachments:'.$agent->id);

        sendReply($this, $agent, $ticket, ['body' => 'solo texto']);

        expect(RateLimiter::remaining('attachments:'.$agent->id, 60))->toBe(60);
    });
});

describe('revisión', function (): void {
    it('cada adjunto nace por revisar y se encola su revisión después de guardar el mensaje', function (): void {
        Queue::fake();
        $tenant = ticketTenant();
        $ticket = makeTicket($tenant);

        sendReply($this, ticketStaff($tenant), $ticket, ['body' => 'x', 'files' => [Documents::upload('a.pdf', Documents::pdf('a')), Documents::upload('b.pdf', Documents::pdf('b'))]]);

        $ids = attachmentsOf($tenant)->pluck('id')->all();
        expect(attachmentsOf($tenant)->pluck('status')->all())->toBe([AttachmentStatus::Pending, AttachmentStatus::Pending]);
        Queue::assertPushed(ScanAttachment::class, 2);
        Queue::assertPushed(ScanAttachment::class, fn (ScanAttachment $job): bool => in_array($job->attachmentId, $ids, true));
    });

    it('si el mensaje falla, no se encola ninguna revisión', function (): void {
        Queue::fake();
        $tenant = ticketTenant();
        $ticket = makeTicket($tenant, ['status' => TicketStatus::Closed]);

        sendReply($this, ticketStaff($tenant), $ticket, ['body' => 'x', 'files' => [Documents::upload('a.pdf', Documents::pdf())]]);

        Queue::assertNothingPushed();
    });

    it('con el antivirus de pruebas, un archivo limpio queda limpio y descargable', function (): void {
        $tenant = ticketTenant();
        $ticket = makeTicket($tenant);

        sendReply($this, ticketStaff($tenant), $ticket, ['body' => 'x', 'files' => [Documents::upload('a.pdf', Documents::pdf())]]);

        expect(attachmentsOf($tenant)->sole())->status->toBe(AttachmentStatus::Clean)->scanned_at->not->toBeNull();
    });
});

it('el antivirus de pruebas no cambia lo que se guarda: ningún archivo se transforma', function (): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant);
    $png = Images::png();

    sendReply($this, ticketStaff($tenant), $ticket, ['body' => 'x', 'files' => [Documents::upload('foto.png', $png)]]);

    $attachment = attachmentsOf($tenant)->sole();
    expect(Storage::disk($attachment->disk)->get($attachment->path))->toBe($png)->and($attachment->sha256)->toBe(hash('sha256', $png));
});
