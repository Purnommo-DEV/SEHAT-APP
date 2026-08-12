<?php

namespace App\Enums;

enum ParticipantStatus: string
{
    case Waiting = 'waiting';
    case HealthCheck = 'health_check';
    case Donating = 'donating';
    case Registered = 'registered';
    case CheckedIn = 'checked_in';
    case WaitingService = 'waiting_service';
    case ServiceInProgress = 'service_in_progress';
    case WaitingHealth = 'waiting_health';
    case HealthInProgress = 'health_in_progress';
    case WaitingScreening = 'waiting_screening';
    case NotEligible = 'not_eligible';
    case WaitingDonor = 'waiting_donor';
    case DonationInProgress = 'donation_in_progress';
    case DonationCompleted = 'donation_completed';
    case HealthCheckCompleted = 'health_check_completed';
    case Finished = 'finished';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Menunggu',
            self::HealthCheck => 'Cek Kesehatan',
            self::Donating => 'Sedang Donor',
            self::Registered => 'Terdaftar',
            self::CheckedIn => 'Check-in',
            self::WaitingService => 'Menunggu pelayanan',
            self::ServiceInProgress => 'Sedang dilayani',
            self::WaitingHealth => 'Menunggu pemeriksaan',
            self::HealthInProgress => 'Sedang diperiksa',
            self::WaitingScreening => 'Menunggu screening',
            self::NotEligible => 'Tidak layak donor',
            self::WaitingDonor => 'Menunggu donor',
            self::DonationInProgress => 'Sedang donor',
            self::DonationCompleted => 'Donor selesai',
            self::HealthCheckCompleted => 'Pemeriksaan kesehatan selesai',
            self::Finished => 'Selesai',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function isOperationalStage(): bool
    {
        return in_array($this, self::operationalStages(), true);
    }

    /**
     * @return list<self>
     */
    public static function operationalStages(): array
    {
        return [
            self::Waiting,
            self::HealthCheck,
            self::Donating,
            self::Finished,
        ];
    }
}
