<?php

declare(strict_types=1);

namespace App\Metrics;

/** Dinero en dólares como se lee en pantalla: «US$ 0,0004» para muy poco y «US$ 2,50» para más. */
final class Money
{
    /** @param int $micro Millonésimas de dólar. */
    public static function usd(int $micro): string
    {
        return 'US$ '.number_format($micro / 1_000_000, $micro < 1000 ? 5 : ($micro < 100_000 ? 4 : 2), ',', '.');
    }
}
