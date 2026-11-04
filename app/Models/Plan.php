<?php

declare(strict_types=1);

namespace App\Models;

use App\Plans\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un plan del catálogo de la plataforma: global, igual para todas las empresas y de solo lectura desde la interfaz.
 * Sin atributos rellenables: el catálogo lo cambia el equipo de la plataforma, no una petición.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property bool $is_default
 * @property bool $is_active
 * @property int|null $max_agents
 * @property int|null $max_channels
 * @property int|null $max_tickets_per_month
 * @property int|null $ai_credits_per_month
 * @property int $tenants_count Solo existe cuando la consulta pidió withCount('tenants').
 */
class Plan extends Model
{
    /**
     * El plan que recibe una empresa nueva.
     */
    public static function default(): self
    {
        return self::query()->where('is_default', true)->where('is_active', true)->firstOrFail();
    }

    /** El tope del límite en este plan; nulo si no tiene. */
    public function limitFor(Limit $limit): ?int
    {
        $value = $this->getAttribute($limit->column());

        return is_int($value) ? $value : null;
    }

    /**
     * @return HasMany<Tenant, $this>
     */
    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }
}
