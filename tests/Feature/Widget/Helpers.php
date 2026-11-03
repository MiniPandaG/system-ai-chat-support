<?php

declare(strict_types=1);

require_once __DIR__.'/../Tickets/Helpers.php';

use App\Branding\Palette;
use App\Enums\Role;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Models\WidgetOrigin;
use App\Models\WidgetSession;
use App\Tenancy\TenantContext;
use App\Widget\SessionToken;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** Una empresa con el widget activo (tiene al menos un sitio permitido) y en un plan holgado. */
function widgetTenant(string $plan = 'negocio', bool $enabled = true): Tenant
{
    $tenant = ticketTenant($plan);

    if ($enabled) {
        widgetOrigin($tenant);
    }

    return $tenant;
}

function widgetOrigin(Tenant $tenant, string $origin = 'https://tienda-ejemplo.com'): WidgetOrigin
{
    return app(TenantContext::class)->run($tenant, function () use ($origin): WidgetOrigin {
        $row = new WidgetOrigin;
        $row->origin = $origin;
        $row->save();

        return $row;
    });
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function widgetPayload(array $overrides = []): array
{
    return ['client_id' => (string) Str::uuid(), 'body' => 'Hola, necesito ayuda con mi pedido.', ...$overrides];
}

/**
 * Envía un mensaje desde el widget: con token continúa su conversación, sin token abre la sesión con la llave.
 *
 * @param  array<string, mixed>  $payload
 */
function widgetSend(object $test, Tenant $tenant, array $payload = [], ?string $token = null): TestResponse
{
    $body = widgetPayload($payload);

    if ($token === null && ! array_key_exists('key', $body)) {
        $body['key'] = $tenant->widget_key;
    }

    return $test->postJson('/widget/api/messages', $body, $token === null ? [] : ['Authorization' => 'Bearer '.$token]);
}

/**
 * Abre una conversación y devuelve el token de su sesión.
 *
 * @param  array<string, mixed>  $payload
 */
function widgetStart(object $test, Tenant $tenant, array $payload = []): string
{
    $token = widgetSend($test, $tenant, $payload)->assertCreated()->json('token');
    expect($token)->toBeString();

    return $token;
}

function widgetGet(object $test, string $path, ?string $token): TestResponse
{
    return $test->getJson('/widget/api/'.$path, $token === null ? [] : ['Authorization' => 'Bearer '.$token]);
}

function widgetSessionOf(Tenant $tenant, string $token): ?WidgetSession
{
    $parsed = SessionToken::parse($token);

    return app(TenantContext::class)->run($tenant, fn () => WidgetSession::query()->where('token_hash', (string) $parsed['hash'])->first());
}

/** El ticket de la conversación de esta sesión. */
function widgetTicketOf(Tenant $tenant, string $token): Ticket
{
    $session = widgetSessionOf($tenant, $token);

    return app(TenantContext::class)->run($tenant, fn () => Ticket::query()->findOrFail($session?->ticket_id));
}

/** @return list<string> Los textos de los mensajes del ticket, en orden. */
function widgetBodies(Tenant $tenant, Ticket $ticket): array
{
    return app(TenantContext::class)->run($tenant, fn () => TicketMessage::query()->where('ticket_id', $ticket->id)->orderBy('id')->pluck('body')->all());
}

function widgetCustomerOf(Tenant $tenant, Ticket $ticket): Customer
{
    return app(TenantContext::class)->run($tenant, fn () => Customer::query()->findOrFail($ticket->customer_id));
}

/** Un administrador de la empresa (con 2FA activo, como exige el panel). */
function widgetAdmin(?Tenant $tenant = null): User
{
    return ticketStaff($tenant ?? widgetTenant(enabled: false), Role::Admin);
}

/** Guarda una paleta como lo hace la pantalla de marca: solo los colores cambiados y los textos calculados. */
function brandPalette(Tenant $tenant, array $colors): void
{
    $stored = Palette::forStorage($colors);
    app(TenantContext::class)->run($tenant, function () use ($stored): void {
        $branding = TenantBranding::current();
        $branding->colors = $stored['colors'];
        $branding->foregrounds = $stored['foregrounds'];
        $branding->save();
    });
}
