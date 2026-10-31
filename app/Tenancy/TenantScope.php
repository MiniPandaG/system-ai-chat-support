<?php

declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filtro automático por empresa. Sin empresa en contexto no devuelve nada: ante la duda, se deniega.
 *
 * @implements Scope<Model>
 */
class TenantScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = app(TenantContext::class)->id();
        $column = $model->qualifyColumn('tenant_id');

        if ($tenantId === null) {
            // Una lista vacía se compila como una condición siempre falsa.
            $builder->whereIn($column, []);

            return;
        }

        $builder->where($column, $tenantId);
    }
}
