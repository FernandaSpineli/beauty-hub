<?php

declare(strict_types=1);

namespace App\Enums;

enum SkinType: string
{
    case Normal = 'normal';
    case Dry = 'dry';
    case Oily = 'oily';
    case Combination = 'combination';
    case Sensitive = 'sensitive';
}
