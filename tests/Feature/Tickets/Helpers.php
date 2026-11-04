<?php

declare(strict_types=1);

use App\Attachments\AttachmentStatus;
use App\Enums\Role;
use App\Models\Customer;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\Models\TicketNote;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tickets\MessageAuthor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** Una empresa en un plan holgado (el de por defecto solo permite 2 personas). */
function ticketTenant(string $plan = 'negocio'): Tenant
{
    return Tenant::factory()->onPlan($plan)->create();
}

/** Un miembro del equipo; los administradores llevan 2FA activo porque lo exige el panel. */
function ticketStaff(Tenant $tenant, Role $role = Role::Agent, bool $disabled = false): User
{
    $user = User::factory()->forTenant($tenant, $role)->create();

    if ($role === Role::Admin) {
        $user->forceFill([
            'two_factor_secret' => encrypt('SECRETOPRUEBA'),
            'two_factor_recovery_codes' => encrypt(json_encode(['x'])),
            'two_factor_confirmed_at' => now(),
        ])->save();
    }

    if ($disabled) {
        $user->forceFill(['disabled_at' => now()])->save();
    }

    return $user;
}

/**
 * Un ticket de ejemplo con su primer mensaje, creado dentro de la empresa y con el número que toca.
 *
 * @param  array<string, mixed>  $attributes
 */
function makeTicket(Tenant $tenant, array $attributes = [], ?Customer $customer = null): Ticket
{
    return app(TenantContext::class)->run($tenant, function () use ($tenant, $attributes, $customer): Ticket {
        $customer ??= Customer::factory()->create();
        $number = $tenant->last_ticket_number + 1;
        $tenant->forceFill(['last_ticket_number' => $number])->save();

        $ticket = Ticket::factory()->forCustomer($customer)->create(['number' => $number, ...$attributes]);
        $message = new TicketMessage(['body' => 'Mensaje de ejemplo del cliente.']);
        $message->forceFill(['ticket_id' => $ticket->id, 'author' => MessageAuthor::Customer])->save();

        return $ticket;
    });
}

/**
 * @return array<string, mixed>
 */
function newTicketPayload(array $overrides = []): array
{
    return [
        'subject' => 'No puedo entrar a mi cuenta',
        'description' => "Intento iniciar sesión y me dice que mi contraseña es incorrecta.\nYa probé restablecerla.",
        'priority' => 'normal',
        'customer_name' => 'Marisol Quintero',
        'customer_email' => 'marisol@cliente-ejemplo.com',
        ...$overrides,
    ];
}

/**
 * Todos los tickets de la empresa, del más antiguo al más reciente.
 *
 * @return Collection<int, Ticket>
 */
function ticketsOf(Tenant $tenant): Collection
{
    return app(TenantContext::class)->run($tenant, fn () => Ticket::query()->orderBy('id')->get());
}

/**
 * Una nota interna escrita por alguien del equipo, creada dentro de la empresa del ticket.
 *
 * @param  array<string, mixed>  $attributes
 */
function makeNote(Ticket $ticket, User $author, array $attributes = []): TicketNote
{
    return app(TenantContext::class)->run($ticket->tenant()->firstOrFail(), function () use ($ticket, $author, $attributes): TicketNote {
        $note = TicketNote::factory()->forTicket($ticket)->writtenBy($author)->create($attributes);

        return $note;
    });
}

/**
 * Una etiqueta de la empresa.
 *
 * @param  array<string, mixed>  $attributes
 */
function makeTag(Tenant $tenant, array $attributes = []): Tag
{
    return app(TenantContext::class)->run($tenant, fn (): Tag => Tag::factory()->create($attributes));
}

/**
 * @return Collection<int, Tag>
 */
function tagsOf(Tenant $tenant): Collection
{
    return app(TenantContext::class)->run($tenant, fn () => Tag::query()->orderBy('id')->get());
}

/** El ticket tal como está guardado ahora, leído dentro de su empresa. */
function reloaded(Ticket $ticket): Ticket
{
    return app(TenantContext::class)->run($ticket->tenant()->firstOrFail(), fn () => Ticket::query()->findOrFail($ticket->id));
}

/**
 * Las filas que la bandeja entregó a la pantalla en la primera carga.
 *
 * @return list<array<string, mixed>>
 */
function inboxRows(TestResponse $response): array
{
    $page = $response->viewData('page');

    return $page['props']['tickets'];
}

/**
 * Los asuntos de la primera carga, en el orden en que llegaron.
 *
 * @return list<string>
 */
function inboxSubjects(TestResponse $response): array
{
    return array_column(inboxRows($response), 'subject');
}

/**
 * Una propiedad de la pantalla de la bandeja (por ejemplo «counts» o «total»).
 */
function inboxProp(TestResponse $response, string $key): mixed
{
    $page = $response->viewData('page');

    return data_get($page['props'], $key);
}

/** @return Collection<int, TicketAttachment> */
function attachmentsOf(Tenant $tenant): Collection
{
    return app(TenantContext::class)->run($tenant, fn () => TicketAttachment::query()->orderBy('created_at')->orderBy('id')->get());
}

function messageCountOf(Ticket $ticket): int
{
    return app(TenantContext::class)->run($ticket->tenant()->firstOrFail(), fn () => TicketMessage::query()->where('ticket_id', $ticket->id)->count());
}

/** Responde a un ticket como esta persona, con el texto y los archivos que se pasen. @param  array<string, mixed>  $payload */
function sendReply(object $test, User $agent, Ticket $ticket, array $payload): TestResponse
{
    return $test->actingAs($agent)->post("/tickets/{$ticket->id}/messages", $payload);
}

/**
 * Un disco de archivos temporal en el sistema de archivos del contenedor y no en la carpeta del proyecto: los
 * antivirus de escritorio (Windows Defender) borran en el acto la cadena de prueba EICAR de un disco montado, y con ella
 * las pruebas de amenazas. Se borra al terminar.
 */
function useTemporaryAttachmentDisk(): void
{
    $root = sys_get_temp_dir().'/gecko-attachments-'.Str::lower(Str::random(10));
    config(['filesystems.disks.attachments_test' => ['driver' => 'local', 'root' => $root, 'throw' => false], 'files.disk' => 'attachments_test']);
    Storage::forgetDisk('attachments_test');

    test()->beforeApplicationDestroyed(function () use ($root): void {
        File::deleteDirectory($root);
    });
}

/** Un adjunto «por revisar» con su archivo ya en el disco, sin pasar por la subida, en un ticket nuevo de la empresa. */
function pendingAttachment(Tenant $tenant, string $content = 'contenido', string $name = 'informe.txt', string $mime = 'text/plain'): TicketAttachment
{
    $ticket = makeTicket($tenant);
    $message = app(TenantContext::class)->run($tenant, fn () => $ticket->messages()->firstOrFail());
    $attachment = app(TenantContext::class)->run($tenant, fn (): TicketAttachment => TicketAttachment::factory()->forMessage($message)->create([
        'original_name' => $name,
        'mime' => $mime,
        'size' => strlen($content),
        'status' => AttachmentStatus::Pending,
    ]));
    $attachment->forceFill(['path' => "tenants/{$tenant->id}/attachments/{$attachment->id}"])->save();
    Storage::disk($attachment->disk)->put((string) $attachment->path, $content);

    return $attachment;
}

/** Un adjunto ya revisado y limpio, listo para descargar. */
function cleanAttachment(Tenant $tenant, string $content = 'contenido del archivo', string $name = 'informe.txt', string $mime = 'text/plain'): TicketAttachment
{
    $attachment = pendingAttachment($tenant, $content, $name, $mime);
    $attachment->forceFill(['status' => AttachmentStatus::Clean, 'scanned_at' => now()])->save();

    return $attachment;
}

/** @return list<string> Los archivos que hay ahora mismo en el disco de pruebas de los archivos. */
function storedFiles(): array
{
    return Storage::disk(config()->string('files.disk'))->allFiles();
}

/**
 * Un ticket con asunto propio, para reconocerlo en una lista.
 *
 * @param  array<string, mixed>  $attributes
 */
function titled(Tenant $tenant, string $subject, array $attributes = [], ?Customer $customer = null): Ticket
{
    return makeTicket($tenant, ['subject' => $subject, ...$attributes], $customer);
}
