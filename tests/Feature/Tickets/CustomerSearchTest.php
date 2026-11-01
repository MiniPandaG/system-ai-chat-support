<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Enums\Role;
use App\Models\Customer;
use App\Tenancy\TenantContext;

function seedCustomers($tenant, array $rows): void
{
    app(TenantContext::class)->run($tenant, function () use ($rows): void {
        foreach ($rows as [$name, $email]) {
            Customer::factory()->create(['name' => $name, 'email' => $email]);
        }
    });
}

it('encuentra clientes por nombre o por correo, sin distinguir mayúsculas', function (): void {
    $tenant = ticketTenant();
    seedCustomers($tenant, [['Marisol Quintero', 'marisol@cliente-ejemplo.com'], ['Julián Pardo', 'jpardo@otra-ejemplo.com'], ['Ana Gómez', null]]);
    $agent = ticketStaff($tenant);

    $this->actingAs($agent)->getJson('/customers/search?q=QUINTERO')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Marisol Quintero');
    $this->actingAs($agent)->getJson('/customers/search?q=otra-ejemplo')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'jpardo@otra-ejemplo.com');
    $this->actingAs($agent)->getJson('/customers/search?q=gómez')->assertOk()->assertJsonPath('data.0.email', null);
});

it('con menos de dos caracteres o sin término no devuelve nada', function (string $query): void {
    $tenant = ticketTenant();
    seedCustomers($tenant, [['Marisol Quintero', 'marisol@cliente-ejemplo.com']]);

    $this->actingAs(ticketStaff($tenant))->getJson('/customers/search'.$query)->assertOk()->assertExactJson(['data' => []]);
})->with(['', '?q=', '?q=m', '?q=%20%20', '?q[]=ma']);

it('los comodines de LIKE se tratan como texto, no como patrón', function (): void {
    $tenant = ticketTenant();
    seedCustomers($tenant, [['Marisol Quintero', null], ['Julián Pardo', null]]);
    $agent = ticketStaff($tenant);

    $this->actingAs($agent)->getJson('/customers/search?q=%25%25')->assertOk()->assertExactJson(['data' => []]);
    $this->actingAs($agent)->getJson('/customers/search?q=__')->assertOk()->assertExactJson(['data' => []]);
});

it('devuelve como máximo diez resultados ordenados por nombre', function (): void {
    $tenant = ticketTenant();
    seedCustomers($tenant, array_map(fn (int $i): array => [sprintf('Cliente %02d', $i), null], range(1, 15)));

    $names = $this->actingAs(ticketStaff($tenant))->getJson('/customers/search?q=Cliente')->assertOk()->assertJsonCount(10, 'data')->json('data.*.name');

    expect($names)->toBe(array_map(fn (int $i): string => sprintf('Cliente %02d', $i), range(1, 10)));
});

it('nunca devuelve clientes de otra empresa', function (): void {
    $a = ticketTenant();
    $b = ticketTenant();
    seedCustomers($a, [['Marisol Propia', null]]);
    seedCustomers($b, [['Marisol Ajena', 'ajena@cliente-ejemplo.com']]);

    $names = $this->actingAs(ticketStaff($a))->getJson('/customers/search?q=Marisol')->assertOk()->json('data.*.name');

    expect($names)->toBe(['Marisol Propia']);
});

it('solo administradores y agentes lo usan', function (Role $role, int $status): void {
    $tenant = ticketTenant();
    seedCustomers($tenant, [['Marisol Quintero', null]]);

    $this->actingAs(ticketStaff($tenant, $role))->getJson('/customers/search?q=Marisol')->assertStatus($status);
})->with([[Role::Admin, 200], [Role::Agent, 200], [Role::Observer, 403]]);

it('no se guarda en caché del navegador y solo expone lo necesario', function (): void {
    $tenant = ticketTenant();
    seedCustomers($tenant, [['Marisol Quintero', 'marisol@cliente-ejemplo.com']]);

    $response = $this->actingAs(ticketStaff($tenant))->getJson('/customers/search?q=Marisol')->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(array_keys($response->json('data.0')))->toBe(['id', 'name', 'email']);
});

it('limita las búsquedas a 60 por minuto por usuario', function (): void {
    $tenant = ticketTenant();
    $agent = ticketStaff($tenant);

    foreach (range(1, 60) as $i) {
        $this->actingAs($agent)->getJson('/customers/search?q=ab')->assertOk();
    }

    $this->actingAs($agent)->getJson('/customers/search?q=ab')->assertTooManyRequests();
});
