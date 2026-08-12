<?php

namespace App\Enums;

enum ParticipantServiceType: string
{
    case Donor = 'donor';
    case HealthCheck = 'health_check';

    public function label(): string
    {
        return match ($this) {
            self::Donor => 'Donor Darah',
            self::HealthCheck => 'Pemeriksaan Kesehatan',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Donor => 'Mengikuti tahap menunggu, cek kesehatan, lalu proses donor.',
            self::HealthCheck => 'Mengikuti alur pemeriksaan kesehatan tanpa form medis.',
        };
    }

    public function entryBehavior(): ServicePostBehavior
    {
        return match ($this) {
            self::Donor => ServicePostBehavior::HealthForm,
            self::HealthCheck => ServicePostBehavior::HealthForm,
        };
    }

    /**
     * @return list<ServicePostBehavior>
     */
    public function requiredBehaviors(): array
    {
        return match ($this) {
            self::Donor => [
                ServicePostBehavior::HealthForm,
                ServicePostBehavior::DonationForm,
            ],
            self::HealthCheck => [
                ServicePostBehavior::HealthForm,
            ],
        };
    }

    public function registrationPriority(): int
    {
        return match ($this) {
            self::Donor => 10,
            self::HealthCheck => 20,
        };
    }

    /**
     * @param  list<self>  $selectedServices
     */
    public function initialStatus(array $selectedServices): ParticipantServiceStatus
    {
        return ParticipantServiceStatus::Pending;
    }

    public function initialParticipantStatus(): ParticipantStatus
    {
        return ParticipantStatus::Waiting;
    }
}
