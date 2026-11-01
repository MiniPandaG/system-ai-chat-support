<?php

declare(strict_types=1);

namespace App\Tickets;

use App\Models\Ticket;
use App\Models\TicketRead;
use App\Models\User;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Lo que cada persona del equipo ha leído. «Sin leer» es de cada persona: un ticket está sin leer para ti si alguien que
 * no eres tú escribió en él (el cliente o un compañero) después de la última vez que lo abriste. Las notas internas no
 * cuentan, y lo que tú mismo escribes tampoco. Todas las consultas parten de modelos con filtro de empresa.
 */
class Reads
{
    /** Cuántos nombres se muestran en «Visto por»; el resto se cuenta aparte. */
    public const SEEN_LIMIT = 5;

    /**
     * Marca el ticket como leído hasta ahora. No escribe nada si ya estaba al día, así que abrir el mismo ticket una y
     * otra vez no genera trabajo. Devuelve si cambió algo (para avisar a quien lo tiene abierto).
     */
    public function markRead(User $user, Ticket $ticket): bool
    {
        $read = TicketRead::query()->where('ticket_id', $ticket->id)->where('user_id', $user->id)->first();

        if ($read !== null && $read->last_read_at->greaterThanOrEqualTo($ticket->last_message_at)) {
            return false;
        }

        try {
            $read ??= new TicketRead;
            $read->forceFill(['ticket_id' => $ticket->id, 'user_id' => $user->id, 'last_read_at' => Carbon::now()])->save();
        } catch (UniqueConstraintViolationException) {
            // Dos pestañas de la misma persona abrieron el ticket a la vez: la otra ya dejó la fila, basta con actualizarla.
            TicketRead::query()->where('ticket_id', $ticket->id)->where('user_id', $user->id)->update(['last_read_at' => Carbon::now()]);
        }

        return true;
    }

    /**
     * De estos tickets, los que están sin leer para esta persona.
     *
     * @param  list<int>  $ticketIds
     * @return list<int>
     */
    public function unreadIds(User $user, array $ticketIds): array
    {
        if ($ticketIds === []) {
            return [];
        }

        // Sin leer: existe un mensaje de alguien que no es esta persona, posterior a lo último que ella leyó (o ella nunca
        // lo abrió). Las notas viven en otra tabla y no entran. Parte de `Ticket`, así que el filtro de empresa se aplica.
        $ids = Ticket::query()
            ->whereIn('tickets.id', $ticketIds)
            ->leftJoin('ticket_reads', function (JoinClause $join) use ($user): void {
                $join->on('ticket_reads.ticket_id', '=', 'tickets.id')->where('ticket_reads.user_id', '=', $user->id);
            })
            ->whereExists(function (QueryBuilder $messages) use ($user): void {
                $messages->from('ticket_messages')
                    ->whereColumn('ticket_messages.ticket_id', 'tickets.id')
                    ->where(fn (QueryBuilder $others) => $others->whereNull('ticket_messages.user_id')->orWhere('ticket_messages.user_id', '!=', $user->id))
                    ->where(fn (QueryBuilder $unseen) => $unseen->whereNull('ticket_reads.last_read_at')->orWhereColumn('ticket_messages.created_at', '>', 'ticket_reads.last_read_at'));
            })
            ->pluck('tickets.id');

        return array_values(array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids->all()));
    }

    /**
     * Compañeros que ya vieron lo último del ticket, sin contar a quien lo está mirando. Solo cuentan los activos.
     *
     * @return array{names: list<string>, more: int}
     */
    public function seenBy(User $viewer, Ticket $ticket): array
    {
        $names = TicketRead::query()
            ->where('ticket_id', $ticket->id)
            ->where('user_id', '!=', $viewer->id)
            ->where('last_read_at', '>=', $ticket->last_message_at)
            ->with('user:id,name,disabled_at')
            ->get()
            ->filter(static fn (TicketRead $read): bool => $read->user !== null && $read->user->disabled_at === null)
            ->map(static fn (TicketRead $read): string => (string) $read->user?->name)
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return ['names' => array_values($names->take(self::SEEN_LIMIT)->all()), 'more' => max(0, $names->count() - self::SEEN_LIMIT)];
    }
}
