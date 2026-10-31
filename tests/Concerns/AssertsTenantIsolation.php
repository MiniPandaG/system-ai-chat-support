<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Tenant;
use App\Models\User;

/**
 * Arnés de aislamiento entre empresas (RS-01, HU-13). Cada recurso nuevo lo usa para demostrar que una empresa
 * que pide datos de otra recibe 404, y no 403: un 403 confirmaría que el recurso existe.
 */
trait AssertsTenantIsolation
{
    /**
     * Crea un usuario de una empresa; si no se pasa empresa, crea una nueva.
     */
    protected function userInTenant(?Tenant $tenant = null): User
    {
        return User::factory()->forTenant($tenant ?? Tenant::factory()->create())->create();
    }

    /**
     * El usuario de otra empresa pide el recurso y recibe 404.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $session  Por ejemplo, la contraseña confirmada que exigen algunas rutas.
     */
    protected function assertOtherTenantGets404(User $intruder, string $method, string $uri, array $data = [], array $session = []): void
    {
        $this->actingAs($intruder)
            ->withSession($session)
            ->call($method, $uri, $data)
            ->assertNotFound();
    }
}
