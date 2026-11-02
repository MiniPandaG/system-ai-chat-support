<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\WidgetSession;
use App\Tenancy\TenantContext;

function widgetSessionCount(object $tenant): int
{
    return app(TenantContext::class)->run($tenant, fn () => WidgetSession::query()->count());
}

it('borra las sesiones caducadas y deja la conversación, el cliente y los mensajes', function (): void {
    $tenant = widgetTenant();
    $token = widgetStart($this, $tenant);
    $ticket = widgetTicketOf($tenant, $token);
    $this->travel(3)->days();

    $this->artisan('widget:prune')->expectsOutput('Sesiones borradas: 1.')->assertSuccessful();

    expect(widgetSessionCount($tenant))->toBe(0);
    app(TenantContext::class)->run($tenant, function () use ($ticket): void {
        expect(Ticket::query()->count())->toBe(1)
            ->and(Customer::query()->count())->toBe(1)
            ->and(TicketMessage::query()->where('ticket_id', $ticket->id)->sole()->widget_session_id)->toBeNull();
    });
});

it('no toca las sesiones vigentes ni las que caducaron hace menos de lo pedido', function (): void {
    $tenant = widgetTenant();
    widgetStart($this, $tenant);
    $this->travel(25)->hours();
    widgetStart($this, $tenant);

    $this->artisan('widget:prune')->expectsOutput('Sesiones borradas: 0.')->assertSuccessful();
    expect(widgetSessionCount($tenant))->toBe(2);

    $this->artisan('widget:prune --days=0')->expectsOutput('Sesiones borradas: 1.')->assertSuccessful();
    expect(widgetSessionCount($tenant))->toBe(1);
});

it('actúa sobre todas las empresas sin mezclarlas', function (): void {
    $first = widgetTenant();
    $second = widgetTenant();
    widgetStart($this, $first);
    widgetStart($this, $second);
    $this->travel(3)->days();

    $this->artisan('widget:prune')->expectsOutput('Sesiones borradas: 2.')->assertSuccessful();

    expect(widgetSessionCount($first))->toBe(0)->and(widgetSessionCount($second))->toBe(0);
});
