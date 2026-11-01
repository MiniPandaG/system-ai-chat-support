<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Tickets\InboxFilters;
use Database\Factories\TicketViewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una combinación de filtros de la bandeja con nombre propio. Es privada de quien la creó o compartida con todo el
 * equipo. Guarda solo valores ya normalizados (`InboxFilters`), nunca una consulta.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property string $name
 * @property bool $shared
 * @property array<string, mixed> $filters
 */
#[Fillable(['name'])]
class TicketView extends Model
{
    /** @use HasFactory<TicketViewFactory> */
    use BelongsToTenant, HasFactory;

    public const MAX_NAME_LENGTH = 60;

    /** Vistas privadas por persona y compartidas por empresa: acota también cuántos contadores calcula cada carga. */
    public const MAX_PRIVATE_PER_USER = 10;

    public const MAX_SHARED_PER_TENANT = 10;

    /**
     * Las vistas que una persona puede ver: las suyas y las compartidas.
     *
     * @param  Builder<TicketView>  $query
     * @return Builder<TicketView>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $visible) => $visible->where('shared', true)->orWhere('user_id', $user->id));
    }

    public function inboxFilters(): InboxFilters
    {
        return InboxFilters::fromArray($this->filters);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['shared' => 'boolean', 'filters' => 'array'];
    }
}
