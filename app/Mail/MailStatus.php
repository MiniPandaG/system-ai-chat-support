<?php

declare(strict_types=1);

namespace App\Mail;

enum MailStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
