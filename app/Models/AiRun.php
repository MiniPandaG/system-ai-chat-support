<?php

declare(strict_types=1);

namespace App\Models;

use App\Ai\AiReason;
use App\Ai\AiRunStatus;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface as Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una ejecución de la IA sobre un mensaje del cliente (RIA-01). Nada es rellenable: la escribe solo el código del motor.
 * Guarda la respuesta de la IA y los fragmentos que citó, nunca el texto del cliente (ese ya está en el ticket).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $ticket_id
 * @property int $trigger_message_id
 * @property string|null $channel
 * @property string|null $mode
 * @property AiRunStatus $status
 * @property AiReason $reason
 * @property string|null $catalog_id
 * @property float|null $top_score
 * @property int|null $confidence
 * @property list<array{kind: string, id: int, title: string, chunk_id: int, score: float}>|null $sources
 * @property string|null $body
 * @property int|null $message_id
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property bool $edited
 * @property Carbon $created_at
 */
class AiRun extends Model
{
    use BelongsToTenant;

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
        return [
            'status' => AiRunStatus::class,
            'reason' => AiReason::class,
            'top_score' => 'float',
            'sources' => 'array',
            'edited' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }
}
