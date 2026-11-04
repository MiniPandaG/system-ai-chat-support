<?php

declare(strict_types=1);

namespace App\Ai;

/** El motivo por el que la IA respondió, no respondió o escaló (RIA-01). Lo decide el código, nunca el modelo. */
enum AiReason: string
{
    case Answered = 'answered';
    case NoKnowledge = 'no_knowledge';
    case ModelDeclined = 'model_declined';
    case NeedsHuman = 'needs_human';
    case Uncited = 'uncited';
    case InvalidOutput = 'invalid_output';
    case Truncated = 'truncated';
    case ProviderError = 'provider_error';
    case ModelUnavailable = 'model_unavailable';
    case InputTooLong = 'input_too_long';
    case LowConfidence = 'low_confidence';
    case CustomerAskedHuman = 'asked_human';
    case Topic = 'topic';
    case MessageLimit = 'message_limit';
    case SpendLimit = 'spend_limit';
    case Credits = 'credits';
    case ChannelOff = 'channel_off';
    case OffSchedule = 'off_schedule';
    case TicketClosed = 'ticket_closed';
    case Assigned = 'assigned';
}
