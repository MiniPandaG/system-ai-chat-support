<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Models\IdempotencyRecord;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

function ticketCount(Tenant $tenant): int
{
    return app(TenantContext::class)->run($tenant, fn () => Ticket::query()->count());
}

describe('repetir una creación', function (): void {
    it('con la misma clave y el mismo contenido devuelve la misma respuesta y no crea otro ticket', function (): void {
        $tenant = widgetTenant();
        $key = apiKeyFor($tenant);
        $headers = ['Idempotency-Key' => 'pedido-1234'];

        $first = api($this, 'POST', '/tickets', $key, newApiTicket(), $headers)->assertCreated();
        $again = api($this, 'POST', '/tickets', $key, newApiTicket(), $headers)->assertCreated();

        expect($again->json())->toBe($first->json())
            ->and($again->headers->get('Idempotent-Replayed'))->toBe('true')->and($first->headers->get('Idempotent-Replayed'))->toBeNull()
            ->and($again->headers->get('Location'))->toBe($first->headers->get('Location'))
            ->and(ticketCount($tenant))->toBe(1);
    });

    it('sin clave cada petición crea la suya', function (): void {
        $tenant = widgetTenant();
        $key = apiKeyFor($tenant);

        api($this, 'POST', '/tickets', $key, newApiTicket())->assertCreated();
        api($this, 'POST', '/tickets', $key, newApiTicket(['customer_email' => 'otro@cliente.com']))->assertCreated();

        expect(ticketCount($tenant))->toBe(2);
    });

    it('la misma clave con otro contenido es un error y no ejecuta nada', function (): void {
        $tenant = widgetTenant();
        $key = apiKeyFor($tenant);
        api($this, 'POST', '/tickets', $key, newApiTicket(), ['Idempotency-Key' => 'k1'])->assertCreated();

        $conflict = api($this, 'POST', '/tickets', $key, newApiTicket(['subject' => 'Otro asunto']), ['Idempotency-Key' => 'k1'])->assertStatus(422);

        expect($conflict->json('error.code'))->toBe('idempotency_conflict')->and(ticketCount($tenant))->toBe(1);
    });

    it('la misma clave en otra ruta no repite la respuesta de la anterior', function (): void {
        $tenant = widgetTenant();
        $key = apiKeyFor($tenant);
        $ticket = makeTicket($tenant);
        api($this, 'POST', '/customers', $key, ['name' => 'Uno'], ['Idempotency-Key' => 'k1'])->assertCreated();

        api($this, 'POST', "/tickets/{$ticket->id}/messages", $key, ['name' => 'Uno'], ['Idempotency-Key' => 'k1'])->assertStatus(422)->assertJsonPath('error.code', 'idempotency_conflict');
    });

    it('cada llave tiene sus propias claves: otra llave no ve ni choca con ellas', function (): void {
        $tenant = widgetTenant();
        $one = apiKeyFor($tenant);
        $two = apiKeyFor($tenant);

        $a = api($this, 'POST', '/customers', $one, ['name' => 'Uno'], ['Idempotency-Key' => 'compartida'])->assertCreated();
        $b = api($this, 'POST', '/customers', $two, ['name' => 'Dos'], ['Idempotency-Key' => 'compartida'])->assertCreated();

        expect($b->json('data.id'))->not->toBe($a->json('data.id'))->and($b->headers->get('Idempotent-Replayed'))->toBeNull();
    });

    it('un error se puede corregir y reintentar con la misma clave', function (): void {
        $tenant = widgetTenant();
        $key = apiKeyFor($tenant);
        $headers = ['Idempotency-Key' => 'k-correccion'];

        api($this, 'POST', '/tickets', $key, newApiTicket(['subject' => '']), $headers)->assertStatus(422);
        $ok = api($this, 'POST', '/tickets', $key, newApiTicket(), $headers)->assertCreated();

        expect($ok->headers->get('Idempotent-Replayed'))->toBeNull()->and(ticketCount($tenant))->toBe(1)
            ->and(app(TenantContext::class)->run($tenant, fn () => IdempotencyRecord::query()->count()))->toBe(1);
    });

    it('una petición igual mientras la primera sigue en curso recibe 409 y no ejecuta nada', function (): void {
        $tenant = widgetTenant();
        $key = apiKeyFor($tenant);
        $headers = ['Idempotency-Key' => 'en-curso'];
        api($this, 'POST', '/customers', $key, ['name' => 'Uno'], $headers)->assertCreated();
        // Como si la primera petición no hubiera terminado: sin respuesta todavía.
        app(TenantContext::class)->run($tenant, fn () => IdempotencyRecord::query()->update(['status_code' => null, 'response_body' => null]));

        $response = api($this, 'POST', '/customers', $key, ['name' => 'Uno'], $headers)->assertStatus(409);

        expect($response->json('error.code'))->toBe('idempotency_in_progress')->and(DB::table('customers')->count())->toBe(1);
    });

    it('una clave vencida se olvida y la petición se ejecuta de nuevo', function (): void {
        $tenant = widgetTenant();
        $key = apiKeyFor($tenant);
        api($this, 'POST', '/customers', $key, ['name' => 'Uno'], ['Idempotency-Key' => 'vieja'])->assertCreated();
        $this->travel(25)->hours();

        $again = api($this, 'POST', '/customers', $key, ['name' => 'Uno'], ['Idempotency-Key' => 'vieja']);

        expect($again->status())->toBe(201)->and($again->headers->get('Idempotent-Replayed'))->toBeNull();
        expect(DB::table('api_idempotency')->count())->toBe(1);
    });

    it('rechaza claves con forma inválida', function (string $header): void {
        $tenant = widgetTenant();

        $response = api($this, 'POST', '/customers', apiKeyFor($tenant), ['name' => 'Uno'], ['Idempotency-Key' => $header])->assertStatus(422);

        expect($response->json('error.code'))->toBe('idempotency_key_invalid');
        expect(DB::table('customers')->count())->toBe(0);
    })->with([
        'con espacios' => ['con espacios'],
        'con símbolos' => ['a;b'],
        'larga' => [str_repeat('a', 65)],
        'con tilde' => ['clavé'],
    ]);

    it('funciona también en mensajes y clientes', function (): void {
        $tenant = widgetTenant();
        $key = apiKeyFor($tenant);
        $ticket = makeTicket($tenant);

        api($this, 'POST', "/tickets/{$ticket->id}/messages", $key, ['body' => 'Hola'], ['Idempotency-Key' => 'm1'])->assertCreated();
        $replay = api($this, 'POST', "/tickets/{$ticket->id}/messages", $key, ['body' => 'Hola'], ['Idempotency-Key' => 'm1'])->assertCreated();

        expect($replay->headers->get('Idempotent-Replayed'))->toBe('true')->and(widgetBodies($tenant, $ticket))->toHaveCount(2);
    });

    it('no guarda ni la clave ni lo que se envió: solo huellas y la respuesta', function (): void {
        $tenant = widgetTenant();
        $key = apiKeyFor($tenant);
        api($this, 'POST', '/customers', $key, ['name' => 'Dato Sensible Privado'], ['Idempotency-Key' => 'clave-visible-123'])->assertCreated();

        $row = DB::table('api_idempotency')->first();

        expect($row->key_hash)->toBe(hash('sha256', 'clave-visible-123'))->and(json_encode($row))->not->toContain('clave-visible-123');
    });

    it('una clave de idempotencia en una lectura no hace nada', function (): void {
        $tenant = widgetTenant();

        api($this, 'GET', '/tickets', apiKeyFor($tenant), [], ['Idempotency-Key' => 'x'])->assertOk();

        expect(DB::table('api_idempotency')->count())->toBe(0);
    });
});
