<?php

declare(strict_types=1);

namespace App\Mail;

enum MailDirection: string
{
    case Out = 'out';
    case In = 'in';
}
