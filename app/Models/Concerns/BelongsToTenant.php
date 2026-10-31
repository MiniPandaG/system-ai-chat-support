<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Tenancy\TenancyViolation;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Obligatorio en todo modelo con datos de una empresa (RS-01): filtra las lecturas por la empresa del contexto y
 * fija o comprueba tenant_id al escribir. La columna no es rellenable por el usuario.
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $contextId = app(TenantContext::class)->id();

            if ($contextId === null) {
                throw TenancyViolation::missingContext($model::class);
            }

            if ($model->getAttribute('tenant_id') === null) {
                $model->setAttribute('tenant_id', $contextId);
            }

            $tenantId = $model->getAttribute('tenant_id');

            if (! is_numeric($tenantId) || (int) $tenantId !== $contextId) {
                throw TenancyViolation::foreignTenant($model::class);
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('tenant_id')) {
                throw TenancyViolation::tenantChanged($model::class);
            }
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
