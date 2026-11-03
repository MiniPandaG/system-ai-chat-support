<?php

declare(strict_types=1);

namespace App\Knowledge;

enum IndexStatus: string
{
    /** En cola o indexándose. */
    case Pending = 'pending';
    case Indexed = 'indexed';
    case Failed = 'failed';
}
