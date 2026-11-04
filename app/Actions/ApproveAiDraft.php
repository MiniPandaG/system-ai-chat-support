<?php

declare(strict_types=1);

namespace App\Actions;

use App\Ai\AiRunStatus;
use App\Enums\AuditEvent;
use App\Knowledge\TextCleaner;
use App\Models\AiRun;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\AuditLogger;
use App\Tickets\MessageAuthor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Una persona aprueba el borrador de la IA y se envía al cliente (HU-08). Si lo envía tal cual, el autor del mensaje es
 * la IA y la persona queda como quien lo aprobó; si lo edita, el texto es suyo y el autor es ella. Un borrador se envía
 * una sola vez: se bloquea la fila y solo uno que sigue «pendiente de revisión» pasa.
 */
class ApproveAiDraft
{
    public function __construct(private readonly AddTicketMessage $add, private readonly AuditLogger $audit) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(User $actor, Ticket $ticket, AiRun $run, ?string $editedBody): TicketMessage
    {
        return DB::transaction(function () use ($actor, $ticket, $run, $editedBody): TicketMessage {
            $locked = AiRun::query()->whereKey($run->id)->lockForUpdate()->first();

            if ($locked === null || $locked->ticket_id !== $ticket->id || $locked->status !== AiRunStatus::Drafted || $locked->body === null) {
                throw ValidationException::withMessages(['draft' => __('ai.errors.draft_gone')]);
            }

            $body = $editedBody === null ? $locked->body : trim(TextCleaner::clean($editedBody));
            $edited = $this->normalize($body) !== $this->normalize($locked->body);

            if ($body === '' || mb_strlen($body) > TicketMessage::MAX_LENGTH) {
                throw ValidationException::withMessages(['body' => __('ai.errors.draft_body')]);
            }

            $message = ($this->add)($actor, $ticket, $body, [], $edited ? MessageAuthor::Agent : MessageAuthor::Ai);

            $locked->forceFill([
                'status' => AiRunStatus::Approved,
                'message_id' => $message->id,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'edited' => $edited,
            ])->save();

            $this->audit->record(AuditEvent::AiDraftApproved, $actor->id, ['run_id' => $locked->id, 'ticket_id' => $ticket->id, 'edited' => $edited]);

            return $message;
        });
    }

    private function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
