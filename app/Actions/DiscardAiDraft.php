<?php

declare(strict_types=1);

namespace App\Actions;

use App\Ai\AiRunStatus;
use App\Broadcasting\LiveUpdates;
use App\Enums\AuditEvent;
use App\Models\AiRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Una persona descarta el borrador de la IA: no se envía nada al cliente y queda constancia de quién lo descartó. */
class DiscardAiDraft
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(User $actor, Ticket $ticket, AiRun $run): void
    {
        DB::transaction(function () use ($actor, $ticket, $run): void {
            $locked = AiRun::query()->whereKey($run->id)->lockForUpdate()->first();

            if ($locked === null || $locked->ticket_id !== $ticket->id || $locked->status !== AiRunStatus::Drafted) {
                throw ValidationException::withMessages(['draft' => __('ai.errors.draft_gone')]);
            }

            $locked->forceFill(['status' => AiRunStatus::Discarded, 'reviewed_by' => $actor->id, 'reviewed_at' => now()])->save();

            $this->audit->record(AuditEvent::AiDraftDiscarded, $actor->id, ['run_id' => $locked->id, 'ticket_id' => $ticket->id]);
        });

        LiveUpdates::ticket($ticket);
    }
}
