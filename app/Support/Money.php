<?php

declare(strict_types=1);

namespace App\Support;

final class Money
{
    /** 123456 → "R$ 1.234,56" */
    public static function brl(int $cents): string
    {
        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }
}
