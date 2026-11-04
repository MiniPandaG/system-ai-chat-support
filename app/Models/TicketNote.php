<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface as Carbon;
use Database\Factories\TicketNoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una nota interna: solo la ve el equipo que atiende. Una prueba de arquitectura limita qué clases pueden usar este
 * modelo, para que ningún canal pensado para el cliente la lea por error.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $ticket_id
 * @property int|null $user_id Nulo cuando la escribió la IA (el resumen con el que pasa un caso a una persona).
 * @property string $body
 * @property Carbon $created_at
 */
#[Fillable(['body'])]
class TicketNote extends Model
{
    /** @use HasFactory<TicketNoteFactory> */
    use BelongsToTenant, HasFactory;

    /** Con microsegundos: la conversación mezcla mensajes y notas por hora de creación y los segundos enteros empatarían. */
    protected $dateFormat = 'Y-m-d H:i:s.u';

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
