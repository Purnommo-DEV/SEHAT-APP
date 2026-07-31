<?php

namespace App\Enums;

enum UserRole: string
{
    case Administrator = 'Administrator';
    case RegistrationCommittee = 'Panitia Registrasi';
    case HealthCommittee = 'Panitia Kesehatan';
    case ScreeningCommittee = 'Panitia Screening';
    case DonationCommittee = 'Panitia Donor';
    case Viewer = 'Viewer';
}
