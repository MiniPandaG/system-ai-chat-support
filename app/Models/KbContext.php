<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface as Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * El contexto de la empresa. Solo lo escribe `App\Knowledge\CompanyContext`: nada es rellenable.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $body
 * @property int|null $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class KbContext extends Model
{
    use BelongsToTenant;

    protected $table = 'kb_context';
}
