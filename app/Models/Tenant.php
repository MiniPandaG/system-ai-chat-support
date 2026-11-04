<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * La empresa en sí no lleva filtro de empresa: es la raíz a la que apuntan los demás modelos.
 *
 * plan_id no es rellenable: solo el registro (plan por defecto) y el comando plans:assign lo fijan.
 *
 * @property int $id
 * @property string $name
 * @property int $plan_id
 * @property int $last_ticket_number
 * @property string $widget_key
 * @property int $members_count Solo existe cuando la consulta pidió el conteo de miembros.
 */
#[Fillable(['name'])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        // La llave pública del widget nace con la empresa; nadie la elige ni la recibe del cliente.
        static::creating(function (Tenant $tenant): void {
            $tenant->widget_key ??= Str::random(32);
        });
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
