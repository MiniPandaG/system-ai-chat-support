<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface as Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Una llamada a un modelo de IA. Solo la escribe `App\Ai\AiGateway`: nada es rellenable ni se guarda texto.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $run_id
 * @property string $purpose
 * @property string $provider
 * @property string $catalog_id
 * @property string $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cost_micro
 * @property int $latency_ms
 * @property string $status
 * @property string|null $error_code
 * @property Carbon|null $created_at
 */
class AiCall extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;
}
