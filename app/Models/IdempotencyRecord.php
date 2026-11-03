<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface as Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * La respuesta recordada de una petición con clave de idempotencia. Lo fija `App\Http\Middleware\IdempotentRequests`.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $api_key_id
 * @property string $key_hash
 * @property string $request_hash
 * @property int|null $status_code
 * @property string|null $response_body
 * @property string|null $location
 * @property Carbon $created_at
 * @property Carbon $expires_at
 */
class IdempotencyRecord extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'api_idempotency';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
