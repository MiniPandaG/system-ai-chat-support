<?php

declare(strict_types=1);

require_once __DIR__.'/../Widget/Helpers.php';

use App\Api\ApiKeys;
use App\Api\ApiPermission;
use App\Models\ApiKey;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Testing\TestResponse;

/** Una llave de la empresa con ese nivel de acceso; devuelve la llave completa. */
function apiKeyFor(Tenant $tenant, ApiPermission $permission = ApiPermission::Admin, ?int $expiresInDays = null): string
{
    $admin = widgetAdmin($tenant);

    return app(TenantContext::class)->run($tenant, fn (): string => app(ApiKeys::class)->create($admin, $tenant, 'prueba', $permission, $expiresInDays)['plain']);
}

/**
 * @param  array<string, mixed>  $data
 * @param  array<string, string>  $headers
 */
function api(object $test, string $method, string $uri, ?string $key, array $data = [], array $headers = []): TestResponse
{
    $headers = $key === null ? $headers : ['Authorization' => 'Bearer '.$key, ...$headers];

    return $test->json($method, '/api/v1'.$uri, $data, $headers);
}

function apiKeyRow(Tenant $tenant, string $plain): ApiKey
{
    return app(TenantContext::class)->run($tenant, fn () => ApiKey::query()->where('key_hash', ApiKeys::hash($plain))->firstOrFail());
}

/** @return array<string, mixed> */
function newApiTicket(array $overrides = []): array
{
    return ['subject' => 'Pedido sin llegar', 'description' => 'Hice el pedido hace una semana y no ha llegado.', 'customer_name' => 'Marisol Quintero', 'customer_email' => 'marisol@cliente-ejemplo.com', ...$overrides];
}
