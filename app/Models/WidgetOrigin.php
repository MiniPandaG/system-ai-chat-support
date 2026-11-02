<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface as Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Un sitio donde la empresa permite incrustar el widget, en la forma exacta que envía el navegador (esquema, servidor y
 * puerto). Lo escribe `App\Widget\Origins`, que lo normaliza y lo valida.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $origin
 * @property Carbon $created_at
 */
class WidgetOrigin extends Model
{
    use BelongsToTenant;
}
