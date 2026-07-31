<?php

namespace App\Enums;

enum QueueType: string
{
    case General = 'general';
    case DonorGlobal = 'donor_global';
    case MaleDonor = 'male_donor';
    case FemaleDonor = 'female_donor';

    public static function forDonorGender(ParticipantGender $gender): self
    {
        return match ($gender) {
            ParticipantGender::Male => self::MaleDonor,
            ParticipantGender::Female => self::FemaleDonor,
        };
    }
}
