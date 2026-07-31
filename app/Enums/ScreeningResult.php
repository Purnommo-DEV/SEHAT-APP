<?php

namespace App\Enums;

enum ScreeningResult: string
{
    case Eligible = 'eligible';
    case NotEligible = 'not_eligible';

    public function label(): string
    {
        return match ($this) {
            self::Eligible => 'Layak Donor',
            self::NotEligible => 'Tidak Layak',
        };
    }
}
