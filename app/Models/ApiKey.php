<?php

declare(strict_types=1);

namespace App\Models;

use App\Api\ApiPermission;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface as Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una llave de API de la empresa. Todo lo fijan `App\Api\ApiKeys` y el middleware que la reconoce: nada es rellenable.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $prefix
 * @property string $key_hash
 * @property ApiPermission $permission
 * @property int|null $created_by
 * @property Carbon|null $expires_at
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 */
class ApiKey extends Model
{
    use BelongsToTenant;

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Sirve ahora: ni revocada ni caducada. */
    public function isUsable(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['permission' => ApiPermission::class, 'expires_at' => 'datetime', 'last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
