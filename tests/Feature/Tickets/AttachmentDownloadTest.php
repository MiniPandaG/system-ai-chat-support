<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Attachments\AttachmentStatus;
use App\Attachments\AttachmentStore;
use App\Enums\Role;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Documents;

beforeEach(function (): void {
    useTemporaryAttachmentDisk();
});

/** La URL firmada que la pantalla entrega para un adjunto. */
function downloadUrl(TicketAttachment $attachment): string
{
    return (string) app(TenantContext::class)->run($attachment->tenant()->firstOrFail(), fn (): ?string => app(AttachmentStore::class)->downloadUrl($attachment));
}

describe('descargar un adjunto limpio', function (): void {
    it('entrega los mismos bytes, como descarga y con las cabeceras de seguridad', function (): void {
        $tenant = ticketTenant();
        $bytes = Documents::pdf('Contrato');
        $attachment = cleanAttachment($tenant, $bytes, 'Contrato final.pdf', 'application/pdf');

        $response = $this->actingAs(ticketStaff($tenant))->get(downloadUrl($attachment));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'")
            ->assertHeader('Cache-Control', 'no-store, private');
        expect($response->streamedContent())->toBe($bytes)
            ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment;')->toContain('Contrato final.pdf');
    });

    it('siempre es una descarga, nunca contenido que el navegador abra: tampoco con un tipo que normalmente se muestra', function (string $name, string $mime): void {
        $tenant = ticketTenant();
        $attachment = cleanAttachment($tenant, 'contenido', $name, $mime);

        $disposition = $this->actingAs(ticketStaff($tenant))->get(downloadUrl($attachment))->assertOk()->headers->get('Content-Disposition');

        expect($disposition)->toStartWith('attachment;')->not->toContain('inline');
    })->with([
        'imagen' => ['foto.png', 'image/png'],
        'pdf' => ['doc.pdf', 'application/pdf'],
        'texto' => ['notas.txt', 'text/plain'],
    ]);

    it('el nombre con caracteres especiales no rompe la cabecera ni inyecta otras', function (): void {
        $tenant = ticketTenant();
        $attachment = cleanAttachment($tenant, 'x', "informe\r\nX-Inyectada: 1 \"final\".txt");

        $response = $this->actingAs(ticketStaff($tenant))->get(downloadUrl($attachment))->assertOk();

        expect($response->headers->get('X-Inyectada'))->toBeNull()->and($response->headers->get('Content-Disposition'))->not->toContain("\n");
    });

    it('lo puede descargar cualquier miembro activo de la empresa, también un observador', function (Role $role): void {
        $tenant = ticketTenant();
        $attachment = cleanAttachment($tenant);

        $this->actingAs(ticketStaff($tenant, $role))->get(downloadUrl($attachment))->assertOk();
    })->with([Role::Admin, Role::Agent, Role::Observer]);
});

describe('lo que no se descarga', function (): void {
    it('un adjunto por revisar, bloqueado o sin poder revisarse no existe para quien lo pide', function (AttachmentStatus $status): void {
        $tenant = ticketTenant();
        $attachment = cleanAttachment($tenant);
        $signed = downloadUrl($attachment);
        $attachment->forceFill(['status' => $status])->save();

        $this->actingAs(ticketStaff($tenant))->get($signed)->assertNotFound();
    })->with([AttachmentStatus::Pending, AttachmentStatus::Infected, AttachmentStatus::Failed]);

    it('ni siquiera se genera un enlace para ellos', function (AttachmentStatus $status): void {
        $tenant = ticketTenant();
        $attachment = pendingAttachment($tenant);
        $attachment->forceFill(['status' => $status])->save();

        expect(app(AttachmentStore::class)->downloadUrl($attachment))->toBeNull();
    })->with([AttachmentStatus::Pending, AttachmentStatus::Infected, AttachmentStatus::Failed]);

    it('si el archivo falta en el disco da 404', function (): void {
        $tenant = ticketTenant();
        $attachment = cleanAttachment($tenant);
        $url = downloadUrl($attachment);
        Storage::disk($attachment->disk)->delete((string) $attachment->path);

        $this->actingAs(ticketStaff($tenant))->get($url)->assertNotFound();
    });
});

describe('quién puede descargar', function (): void {
    it('sin sesión se va al inicio de sesión aunque el enlace sea válido', function (): void {
        $tenant = ticketTenant();
        $attachment = cleanAttachment($tenant);

        $this->get(downloadUrl($attachment))->assertRedirect('/login');
    });

    it('un enlace sin firma, con la firma alterada o caducado da 403', function (): void {
        $tenant = ticketTenant();
        $attachment = cleanAttachment($tenant);
        $agent = ticketStaff($tenant);
        $signed = downloadUrl($attachment);

        $this->actingAs($agent)->get("/attachments/{$attachment->id}")->assertForbidden();
        $this->actingAs($agent)->get($signed.'0')->assertForbidden();
        $this->actingAs($agent)->get(str_replace('signature=', 'signature=x', $signed))->assertForbidden();

        $expired = URL::temporarySignedRoute('attachments.download', now()->subMinute(), ['attachment' => $attachment->id]);
        $this->actingAs($agent)->get($expired)->assertForbidden();
    });

    it('otra empresa recibe 404 aunque tenga el enlace firmado', function (): void {
        $attachment = cleanAttachment(ticketTenant());
        $signed = downloadUrl($attachment);

        $this->actingAs(ticketStaff(ticketTenant(), Role::Admin))->get($signed)->assertNotFound();
    });

    it('un usuario desactivado y el personal de la plataforma no descargan', function (): void {
        $tenant = ticketTenant();
        $attachment = cleanAttachment($tenant);
        $signed = downloadUrl($attachment);
        $staff = User::factory()->create(['is_platform_admin' => true]);
        $staff->forceFill(['two_factor_secret' => encrypt('S'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()])->save();

        $this->actingAs(ticketStaff($tenant, Role::Agent, disabled: true))->get($signed)->assertRedirect(route('login'));
        $this->actingAs($staff)->get($signed)->assertNotFound();
    });

    it('el identificador debe tener el formato de un identificador', function (string $id): void {
        $this->actingAs(ticketStaff(ticketTenant()))->get("/attachments/{$id}")->assertNotFound();
    })->with(['1', 'abc', '../../etc/passwd', str_repeat('a', 27), strtoupper(str_repeat('a', 26))]);

    it('sin empresa en contexto no se lee ningún adjunto', function (): void {
        $tenant = ticketTenant();
        cleanAttachment($tenant);

        expect(TicketAttachment::query()->count())->toBe(0);
    });
});

describe('en la conversación', function (): void {
    it('el adjunto limpio llega con su enlace; el que no lo está, sin enlace y con su estado', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $ticket = makeTicket($tenant);
        $message = app(TenantContext::class)->run($tenant, fn () => $ticket->messages()->firstOrFail());
        foreach ([AttachmentStatus::Clean, AttachmentStatus::Pending, AttachmentStatus::Infected, AttachmentStatus::Failed] as $status) {
            app(TenantContext::class)->run($tenant, fn () => TicketAttachment::factory()->forMessage($message)->status($status)->create(['original_name' => "{$status->value}.pdf"]));
        }

        $response = $this->actingAs($agent)->get("/tickets/{$ticket->id}");

        $attachments = inboxProp($response, 'selected.thread.0.attachments');
        expect(array_column($attachments, 'status', 'name'))->toBe(['clean.pdf' => 'clean', 'pending.pdf' => 'pending', 'infected.pdf' => 'infected', 'failed.pdf' => 'failed'])
            ->and(array_filter(array_column($attachments, 'url', 'name')))->toHaveCount(1)->toHaveKey('clean.pdf');
    });

    it('el observador ve los adjuntos y puede descargar los limpios', function (): void {
        $tenant = ticketTenant();
        $attachment = cleanAttachment($tenant, 'hola', 'nota.txt');

        $response = $this->actingAs(ticketStaff($tenant, Role::Observer))->get("/tickets/{$attachment->ticket_id}")->assertOk();

        $url = inboxProp($response, 'selected.thread.0.attachments.0.url');
        expect($url)->toBeString();
        $this->actingAs(ticketStaff($tenant, Role::Observer))->get($url)->assertOk();
    });

    it('el enlace es el mismo dentro de una ventana, para que el navegador lo reutilice', function (): void {
        $tenant = ticketTenant();
        $attachment = cleanAttachment($tenant);

        expect(downloadUrl($attachment))->toBe(downloadUrl($attachment));
    });

    it('un ticket con muchos adjuntos no hace consultas de más por cada uno', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $ticket = makeTicket($tenant);
        $message = app(TenantContext::class)->run($tenant, fn () => $ticket->messages()->firstOrFail());
        $add = fn (int $count) => app(TenantContext::class)->run($tenant, fn () => TicketAttachment::factory()->count($count)->forMessage($message)->status(AttachmentStatus::Clean)->create());
        $add(2);
        $this->actingAs($agent)->get("/tickets/{$ticket->id}");

        DB::enableQueryLog();
        $this->actingAs($agent)->get("/tickets/{$ticket->id}")->assertOk();
        $few = count(DB::getQueryLog());
        DB::flushQueryLog();
        $add(8);
        DB::flushQueryLog();
        $this->actingAs($agent)->get("/tickets/{$ticket->id}")->assertOk();
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($many)->toBe($few);
    });

    it('un adjunto de otra empresa nunca aparece en esta conversación', function (): void {
        $mine = ticketTenant();
        $ticket = makeTicket($mine);
        cleanAttachment(ticketTenant(), 'ajeno', 'ajeno.txt');

        $response = $this->actingAs(ticketStaff($mine))->get("/tickets/{$ticket->id}");

        expect(inboxProp($response, 'selected.thread.0.attachments'))->toBe([]);
    });

    it('las notas internas no llevan adjuntos', function (): void {
        $tenant = ticketTenant();
        $agent = ticketStaff($tenant);
        $ticket = makeTicket($tenant);
        makeNote($ticket, $agent);

        $this->actingAs($agent)->get("/tickets/{$ticket->id}")->assertInertia(fn (AssertableInertia $page) => $page->missing('selected.thread.1.attachments'));
    });
});
