<?php

namespace App\Enums;

enum RegistrationNumberFormat: string
{
    case Uniform = 'uniform';
    case GenderPrefix = 'gender_prefix';

    public function label(): string
    {
        return match ($this) {
            self::Uniform => 'Satu prefix',
            self::GenderPrefix => 'Prefix sesuai jenis kelamin',
        };
    }
}
