<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface as Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hasta cuándo leyó una persona del equipo un ticket. Es el estado de lectura de cada persona, no del ticket: lo que
 * una persona leyó no cambia lo que otra ve como pendiente. Sin atributos rellenables: se escribe solo desde `Reads`.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $ticket_id
 * @property int $user_id
 * @property Carbon $last_read_at
 */
class TicketRead extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    /** Con microsegundos: se compara con la hora de los mensajes, que también los lleva. */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_read_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
