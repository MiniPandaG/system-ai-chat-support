<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface as Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La sesión anónima de un visitante del widget. Guarda la huella del token (nunca el token), el cliente que se creó
 * para él y el ticket en el que conversa ahora. Todo lo fija el servidor: nada de esto es rellenable.
 *
 * @property string $id
 * @property int $tenant_id
 * @property int $customer_id
 * @property int|null $ticket_id
 * @property string $token_hash
 * @property Carbon $last_seen_at
 * @property Carbon $expires_at
 * @property Carbon $created_at
 */
class WidgetSession extends Model
{
    use BelongsToTenant, HasUlids;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

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
        return ['last_seen_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}
