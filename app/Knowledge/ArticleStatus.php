<?php

declare(strict_types=1);

namespace App\Knowledge;

enum ArticleStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
