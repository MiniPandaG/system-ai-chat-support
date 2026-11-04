<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface as Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La encuesta de satisfacción de un ticket y, cuando el cliente la contesta, su puntaje y su comentario (texto plano).
 * Nada es rellenable: la escribe solo `App\Csat\Surveys`.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $ticket_id
 * @property string $token_hash
 * @property Carbon $sent_at
 * @property Carbon $expires_at
 * @property Carbon|null $answered_at
 * @property string|null $channel
 * @property int|null $score
 * @property string|null $comment
 */
class CsatResponse extends Model
{
    use BelongsToTenant;

    /** ¿Todavía se puede responder? Sin contestar y sin caducar. */
    public function isOpen(): bool
    {
        return $this->answered_at === null && $this->expires_at->isFuture();
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
        return ['sent_at' => 'datetime', 'expires_at' => 'datetime', 'answered_at' => 'datetime', 'score' => 'integer'];
    }
}
