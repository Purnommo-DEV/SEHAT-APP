<?php

namespace App\Enums;

enum DonorNumberMode: string
{
    case Global = 'global';
    case GenderSeparated = 'gender_separated';

    public function label(): string
    {
        return match ($this) {
            self::Global => 'Global',
            self::GenderSeparated => 'Terpisah laki-laki / perempuan',
        };
    }
}
