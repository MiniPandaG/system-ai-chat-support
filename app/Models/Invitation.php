<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface as Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $email
 * @property Role $role
 * @property string $token_hash
 * @property int|null $invited_by
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 */
#[Fillable(['email', 'role'])]
class Invitation extends Model
{
    use BelongsToTenant;

    /** Horas que dura el enlace de una invitación. */
    public const TTL_HOURS = 72;

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Invitaciones que siguen abiertas: ni aceptadas ni canceladas (pueden estar caducadas).
     *
     * @param  Builder<Invitation>  $query
     * @return Builder<Invitation>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->whereNull('revoked_at');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /** Se puede aceptar: abierta y sin caducar. */
    public function isUsable(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && ! $this->isExpired();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
