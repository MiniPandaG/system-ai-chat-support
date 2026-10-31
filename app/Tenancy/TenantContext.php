<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Tenant;
use Closure;

/**
 * La empresa con la que trabaja la petición, el job o el comando en curso. Se registra como instancia de ámbito
 * (scoped), así que no sobrevive entre peticiones ni entre jobs de un mismo trabajador.
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    public function current(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        $key = $this->tenant?->getKey();

        return is_int($key) ? $key : null;
    }

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    /**
     * Ejecuta el código dentro de una empresa y restablece la anterior al salir, incluso si falla.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function run(Tenant $tenant, Closure $callback): mixed
    {
        $previous = $this->tenant;
        $this->tenant = $tenant;

        try {
            return $callback();
        } finally {
            $this->tenant = $previous;
        }
    }
}
