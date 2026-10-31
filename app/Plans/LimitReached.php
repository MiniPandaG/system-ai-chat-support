<?php

declare(strict_types=1);

namespace App\Plans;

use RuntimeException;

/**
 * Una acción pasaría el límite del plan. El mensaje ya está en español, dice qué límite es, cuánto se usa y qué
 * hacer, y se muestra tal cual a quien lo pidió.
 */
class LimitReached extends RuntimeException
{
    public function __construct(
        public readonly Limit $limit,
        public readonly ?int $max,
        public readonly int $used,
        public readonly int $adding,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function for(Limit $limit, string $plan, int $max, int $used, int $adding): self
    {
        $remaining = max(0, $max - $used);
        $replace = ['plan' => $plan, 'max' => $max, 'used' => $used, 'remaining' => $remaining, 'adding' => $adding];

        $message = $adding > 1 && $remaining > 0
            ? trans_choice('plans.reached.'.$limit->value.'_some', $remaining, $replace)
            : trans_choice('plans.reached.'.$limit->value, $max, $replace);

        return new self($limit, $max, $used, $adding, $message);
    }
}
