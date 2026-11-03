<?php

declare(strict_types=1);

require_once __DIR__.'/../Widget/Helpers.php';

use App\Mail\Threads;
use App\Models\Customer;
use App\Models\MailMessage;
use App\Models\MailThread;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Tenancy\TenantContext;

/** Un ticket de la empresa con un cliente que sí dejó su correo. */
function mailTicket(Tenant $tenant, ?string $email = 'marisol@cliente-ejemplo.com', string $name = 'Marisol Quintero'): Ticket
{
    $customer = app(TenantContext::class)->run($tenant, fn () => Customer::factory()->create(['name' => $name, 'email' => $email]));

    return makeTicket($tenant, [], $customer);
}

function mailThreadOf(Tenant $tenant, Ticket $ticket): ?MailThread
{
    return app(TenantContext::class)->run($tenant, fn () => MailThread::query()->where('ticket_id', $ticket->id)->first());
}

/** @return list<MailMessage> */
function mailRows(Tenant $tenant): array
{
    return app(TenantContext::class)->run($tenant, fn () => MailMessage::query()->orderBy('id')->get()->all());
}

/** El token de un ticket (lo crea si hace falta), tal como viaja en las direcciones. */
function threadToken(Tenant $tenant, Ticket $ticket): string
{
    return app(TenantContext::class)->run($tenant, fn (): string => app(Threads::class)->forTicket($ticket)->token);
}
