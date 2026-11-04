<?php

declare(strict_types=1);

namespace App\Ai;

/** Para qué se llama a un modelo. Lista cerrada: se guarda en el registro de consumo. */
enum AiPurpose: string
{
    case Reply = 'reply';
    case Summary = 'summary';
}
