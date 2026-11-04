<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface as Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * El consumo de IA de un día (UTC) de una empresa. Solo lo escribe `App\Ai\AiGateway`.
 *
 * @property int $id
 * @property int $tenant_id
 * @property Carbon $day
 * @property int $calls
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cost_micro
 */
class AiUsageDaily extends Model
{
    use BelongsToTenant;

    protected $table = 'ai_usage_daily';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['day' => 'date'];
    }
}
