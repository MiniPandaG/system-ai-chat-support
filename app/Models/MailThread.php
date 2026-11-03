<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface as Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El hilo de correo de un ticket. El token es la «llave» que viaja en la dirección de respuesta: se guarda cifrado y con
 * su huella. Todo lo fija `App\Mail\Threads`; nada es rellenable.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $ticket_id
 * @property string $token
 * @property string $token_hash
 * @property Carbon|null $created_at
 */
class MailThread extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['token' => 'encrypted'];
    }
}
