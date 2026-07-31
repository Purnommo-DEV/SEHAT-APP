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
            self::HealthCheck => 'Cek Kesehatan',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Donor => 'Pemeriksaan kelayakan lalu donor jika layak.',
            self::HealthCheck => 'Langsung dilayani, atau setelah donor bila keduanya dipilih.',
        };
    }

    public function entryBehavior(): ServicePostBehavior
    {
        return match ($this) {
            self::Donor => ServicePostBehavior::ScreeningForm,
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
                ServicePostBehavior::ScreeningForm,
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
        return match ($this) {
            self::Donor => ParticipantServiceStatus::WaitingScreening,
            self::HealthCheck => in_array(self::Donor, $selectedServices, true)
                ? ParticipantServiceStatus::Pending
                : ParticipantServiceStatus::WaitingHealthCheck,
        };
    }

    public function initialParticipantStatus(): ParticipantStatus
    {
        return match ($this) {
            self::Donor => ParticipantStatus::WaitingScreening,
            self::HealthCheck => ParticipantStatus::WaitingHealth,
        };
    }
}
