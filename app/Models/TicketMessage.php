<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Tickets\MessageAuthor;
use Carbon\CarbonInterface as Carbon;
use Database\Factories\TicketMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un mensaje que el cliente puede ver. Texto plano: nunca se interpreta como HTML. Las notas internas viven en otra
 * tabla para que ninguna consulta de mensajes pueda devolverlas por error.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $ticket_id
 * @property MessageAuthor $author
 * @property int|null $user_id
 * @property string $body
 * @property Carbon $created_at
 */
#[Fillable(['body'])]
class TicketMessage extends Model
{
    /** @use HasFactory<TicketMessageFactory> */
    use BelongsToTenant, HasFactory;

    /** Con microsegundos: la conversación mezcla mensajes y notas por hora de creación y los segundos enteros empatarían. */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** Caracteres que admite un mensaje. */
    public const MAX_LENGTH = 10000;

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return HasMany<TicketAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['author' => MessageAuthor::class];
    }
}
