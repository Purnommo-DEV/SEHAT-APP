<?php

namespace App\Data;

use App\Enums\ParticipantGender;

readonly class DonationCapacitySnapshot
{
    public function __construct(
        public DonationCapacityLaneSnapshot $male,
        public DonationCapacityLaneSnapshot $female,
    ) {}

    public function forGender(ParticipantGender $gender): DonationCapacityLaneSnapshot
    {
        return $gender === ParticipantGender::Male ? $this->male : $this->female;
    }

    public function totalCapacity(): int
    {
        return $this->male->capacity + $this->female->capacity;
    }

    public function totalActiveDonations(): int
    {
        return $this->male->activeDonations + $this->female->activeDonations;
    }

    public function totalAvailableSlots(): int
    {
        return $this->male->availableSlots() + $this->female->availableSlots();
    }

    /** @return array{male: array<string, int|string|bool>, female: array<string, int|string|bool>, total: array{capacity: int, active: int, active_donations: int, available: int, available_slots: int, is_full: bool}} */
    public function toArray(): array
    {
        return [
            'male' => $this->male->toArray(),
            'female' => $this->female->toArray(),
            'total' => [
                'capacity' => $this->totalCapacity(),
                'active' => $this->totalActiveDonations(),
                'active_donations' => $this->totalActiveDonations(),
                'available' => $this->totalAvailableSlots(),
                'available_slots' => $this->totalAvailableSlots(),
                'is_full' => $this->totalAvailableSlots() === 0,
            ],
        ];
    }
}
