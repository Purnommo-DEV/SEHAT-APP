<?php

namespace App\Data;

use App\Enums\ParticipantGender;

readonly class DonationCapacityLaneSnapshot
{
    public function __construct(
        public ParticipantGender $gender,
        public int $capacity,
        public int $activeDonations,
    ) {}

    public function availableSlots(): int
    {
        return max(0, $this->capacity - $this->activeDonations);
    }

    public function isFull(): bool
    {
        return $this->availableSlots() === 0;
    }

    /**
     * @return array{gender: string, label: string, capacity: int, active: int, active_donations: int, available: int, available_slots: int, is_full: bool}
     */
    public function toArray(): array
    {
        return [
            'gender' => $this->gender->value,
            'label' => $this->gender->label(),
            'capacity' => $this->capacity,
            'active' => $this->activeDonations,
            'active_donations' => $this->activeDonations,
            'available' => $this->availableSlots(),
            'available_slots' => $this->availableSlots(),
            'is_full' => $this->isFull(),
        ];
    }
}
