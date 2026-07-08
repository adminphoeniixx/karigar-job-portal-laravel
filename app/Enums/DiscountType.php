<?php

namespace App\Enums;

enum DiscountType: string
{
    case Percent = 'percent';
    case Flat = 'flat';

    public function label(): string
    {
        return match ($this) {
            self::Percent => 'Percentage',
            self::Flat => 'Flat amount',
        };
    }
}
