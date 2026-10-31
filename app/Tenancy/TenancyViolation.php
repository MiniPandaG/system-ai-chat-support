<?php

declare(strict_types=1);

namespace App\Tenancy;

use LogicException;

/**
 * Un intento de escribir datos de empresa sin contexto o en una empresa que no es la actual. Es un error de
 * programación, no de usuario: nunca debe llegar a producción.
 */
class TenancyViolation extends LogicException
{
    public static function missingContext(string $model): self
    {
        return new self("No se puede crear {$model} sin una empresa en contexto.");
    }

    public static function foreignTenant(string $model): self
    {
        return new self("{$model} no puede escribirse en una empresa distinta de la del contexto.");
    }

    public static function tenantChanged(string $model): self
    {
        return new self("{$model} no puede cambiar de empresa una vez creado.");
    }
}
