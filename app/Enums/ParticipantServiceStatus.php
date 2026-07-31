<?php

namespace App\Enums;

enum ParticipantServiceStatus: string
{
    case Pending = 'pending';
    case WaitingScreening = 'waiting_screening';
    case ScreeningInProgress = 'screening_in_progress';
    case WaitingDonation = 'waiting_donation';
    case DonationInProgress = 'donation_in_progress';
    case WaitingHealthCheck = 'waiting_health_check';
    case HealthCheckInProgress = 'health_check_in_progress';
    case Completed = 'completed';
    case NotEligible = 'not_eligible';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu layanan sebelumnya',
            self::WaitingScreening => 'Menunggu pemeriksaan kelayakan',
            self::ScreeningInProgress => 'Pemeriksaan kelayakan berlangsung',
            self::WaitingDonation => 'Menunggu donor',
            self::DonationInProgress => 'Donor berlangsung',
            self::WaitingHealthCheck => 'Menunggu pemeriksaan kesehatan',
            self::HealthCheckInProgress => 'Pemeriksaan kesehatan berlangsung',
            self::Completed => 'Selesai',
            self::NotEligible => 'Tidak layak donor',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::NotEligible, self::Cancelled => true,
            default => false,
        };
    }
}
