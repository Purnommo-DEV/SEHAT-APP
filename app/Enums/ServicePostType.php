<?php

namespace App\Enums;

enum ServicePostType: string
{
    case Registration = 'registration';
    case Health = 'health';
    case Screening = 'screening';
    case Donation = 'donation';
    case Completion = 'completion';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Registration => 'Registrasi',
            self::Health => 'Pemeriksaan Kesehatan',
            self::Screening => 'Screening Donor',
            self::Donation => 'Donor',
            self::Completion => 'Selesai',
            self::Custom => 'Pos Khusus',
        };
    }
}
