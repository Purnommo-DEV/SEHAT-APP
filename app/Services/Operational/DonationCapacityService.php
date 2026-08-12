<?php

namespace App\Services\Operational;

use App\Data\DonationCapacityLaneSnapshot;
use App\Data\DonationCapacitySnapshot;
use App\Enums\AuditAction;
use App\Enums\EventStatus;
use App\Enums\ParticipantGender;
use App\Enums\ParticipantStatus;
use App\Models\Event;
use App\Models\EventDonationCapacityLane;
use App\Models\EventParticipant;
use App\Models\EventSetting;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Realtime\WorkflowRealtimePublisher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;

class DonationCapacityService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly AuditLogger $auditLogger,
        private readonly WorkflowRealtimePublisher $realtimePublisher,
    ) {}

    public function snapshot(Event $event): DonationCapacitySnapshot
    {
        return $this->snapshotFor($event, $this->settingsFor($event));
    }

    public function getCapacity(Event $event, ParticipantGender $gender): int
    {
        return $this->capacityFromSettings($this->settingsFor($event), $gender);
    }

    public function getActiveDonationCount(Event $event, ParticipantGender $gender): int
    {
        return $this->activeDonationCounts($event)[$gender->value] ?? 0;
    }

    public function getAvailableSlots(Event $event, ParticipantGender $gender): int
    {
        $lane = $this->snapshot($event)->forGender($gender);

        return $lane->availableSlots();
    }

    public function hasAvailableSlot(Event $event, ParticipantGender $gender): bool
    {
        return ! $this->snapshot($event)->forGender($gender)->isFull();
    }

    /**
     * Reserves a slot while the surrounding transaction is open. The caller
     * must transition the participant to donating before committing.
     */
    public function reserveDonationSlot(Event $event, ParticipantGender $gender): DonationCapacityLaneSnapshot
    {
        $this->lockGenderLane($event, $gender);
        $lane = $this->snapshotFor($event, $this->settingsFor($event))->forGender($gender);

        if ($lane->isFull()) {
            throw ValidationException::withMessages([
                'participant' => "Kapasitas donor {$gender->label()} sudah penuh.",
            ]);
        }

        return $lane;
    }

    /**
     * Serializes a donor completion with a new reservation for the same
     * gender, so a slot is released exactly when its completion commits.
     */
    public function lockGenderLane(Event $event, ParticipantGender $gender): void
    {
        $this->ensureGenderLane($event, $gender);

        EventDonationCapacityLane::query()
            ->where('event_id', $event->id)
            ->where('gender', $gender->value)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function updateCapacity(
        Event $event,
        ParticipantGender $gender,
        int $capacity,
        User $actor,
    ): DonationCapacitySnapshot {
        /** @var DonationCapacitySnapshot $snapshot */
        $snapshot = $this->database->transaction(function () use ($event, $gender, $capacity, $actor): DonationCapacitySnapshot {
            $this->ensureActive($event);
            $this->lockGenderLane($event, $gender);

            /** @var EventSetting $settings */
            $settings = EventSetting::query()
                ->where('event_id', $event->id)
                ->lockForUpdate()
                ->firstOrFail();
            $active = $this->activeDonationCounts($event)[$gender->value] ?? 0;

            $this->validateCapacity($gender, $capacity, $active);
            $oldCapacity = $this->capacityFromSettings($settings, $gender);
            $this->setCapacity($settings, $gender, $capacity);
            $settings->save();
            $this->recordUpdate($event, $actor, $gender, $oldCapacity, $capacity, $active);

            return $this->snapshotFor($event, $settings);
        });

        $this->realtimePublisher->donationCapacityUpdated($event);

        return $snapshot;
    }

    public function updateCapacities(
        Event $event,
        int $maleCapacity,
        int $femaleCapacity,
        User $actor,
    ): DonationCapacitySnapshot {
        /** @var DonationCapacitySnapshot $snapshot */
        $snapshot = $this->database->transaction(function () use ($event, $maleCapacity, $femaleCapacity, $actor): DonationCapacitySnapshot {
            $this->ensureActive($event);

            // Always acquire both lane locks in the same order. This avoids a
            // deadlock when two authorized users save the two-input form.
            $this->lockGenderLane($event, ParticipantGender::Male);
            $this->lockGenderLane($event, ParticipantGender::Female);

            /** @var EventSetting $settings */
            $settings = EventSetting::query()
                ->where('event_id', $event->id)
                ->lockForUpdate()
                ->firstOrFail();
            $counts = $this->activeDonationCounts($event);
            $updates = [
                ParticipantGender::Male->value => $maleCapacity,
                ParticipantGender::Female->value => $femaleCapacity,
            ];

            foreach (ParticipantGender::cases() as $gender) {
                $this->validateCapacity($gender, $updates[$gender->value], $counts[$gender->value] ?? 0);
            }

            foreach (ParticipantGender::cases() as $gender) {
                $newCapacity = $updates[$gender->value];
                $oldCapacity = $this->capacityFromSettings($settings, $gender);

                if ($newCapacity === $oldCapacity) {
                    continue;
                }

                $this->setCapacity($settings, $gender, $newCapacity);
                $this->recordUpdate($event, $actor, $gender, $oldCapacity, $newCapacity, $counts[$gender->value] ?? 0);
            }

            if ($settings->isDirty()) {
                $settings->save();
            }

            return $this->snapshotFor($event, $settings, $counts);
        });

        $this->realtimePublisher->donationCapacityUpdated($event);

        return $snapshot;
    }

    private function ensureGenderLane(Event $event, ParticipantGender $gender): void
    {
        EventDonationCapacityLane::query()->insertOrIgnore([
            'event_id' => $event->id,
            'gender' => $gender->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ensureActive(Event $event): void
    {
        $active = Event::query()->whereKey($event->id)->firstOrFail();

        if ($active->status !== EventStatus::Active || $active->active_marker !== 'active') {
            throw ValidationException::withMessages([
                'event' => 'Kapasitas donor hanya dapat diubah pada event aktif.',
            ]);
        }
    }

    private function settingsFor(Event $event): EventSetting
    {
        /** @var EventSetting $settings */
        $settings = EventSetting::query()->where('event_id', $event->id)->firstOrFail();

        return $settings;
    }

    /**
     * @return array<string, int>
     */
    private function activeDonationCounts(Event $event): array
    {
        return EventParticipant::query()
            ->join('participants', 'participants.id', '=', 'event_participants.participant_id')
            ->where('event_participants.event_id', $event->id)
            ->where('event_participants.status', ParticipantStatus::Donating->value)
            ->select('participants.gender')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('participants.gender')
            ->pluck('total', 'participants.gender')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }

    /**
     * @param  array<string, int>|null  $counts
     */
    private function snapshotFor(Event $event, EventSetting $settings, ?array $counts = null): DonationCapacitySnapshot
    {
        $counts ??= $this->activeDonationCounts($event);

        return new DonationCapacitySnapshot(
            male: new DonationCapacityLaneSnapshot(
                ParticipantGender::Male,
                $settings->donation_capacity_male,
                $counts[ParticipantGender::Male->value] ?? 0,
            ),
            female: new DonationCapacityLaneSnapshot(
                ParticipantGender::Female,
                $settings->donation_capacity_female,
                $counts[ParticipantGender::Female->value] ?? 0,
            ),
        );
    }

    private function capacityFromSettings(EventSetting $settings, ParticipantGender $gender): int
    {
        return $gender === ParticipantGender::Male
            ? $settings->donation_capacity_male
            : $settings->donation_capacity_female;
    }

    private function setCapacity(EventSetting $settings, ParticipantGender $gender, int $capacity): void
    {
        if ($gender === ParticipantGender::Male) {
            $settings->donation_capacity_male = $capacity;

            return;
        }

        $settings->donation_capacity_female = $capacity;
    }

    private function validateCapacity(ParticipantGender $gender, int $capacity, int $active): void
    {
        if ($capacity < $active) {
            throw ValidationException::withMessages([
                'donation_capacity_'.$gender->value => "Kapasitas tidak dapat dikurangi karena masih ada {$active} peserta ".mb_strtolower($gender->label()).' yang sedang donor.',
            ]);
        }
    }

    private function recordUpdate(
        Event $event,
        User $actor,
        ParticipantGender $gender,
        int $oldCapacity,
        int $newCapacity,
        int $activeDonations,
    ): void {
        $this->auditLogger->record(
            actor: $actor,
            subject: $event,
            action: AuditAction::DonationCapacityUpdated,
            eventId: $event->id,
            oldValues: ['gender' => $gender->value, 'capacity' => $oldCapacity],
            newValues: [
                'gender' => $gender->value,
                'capacity' => $newCapacity,
                'active_donations' => $activeDonations,
                'updated_at' => now()->toIso8601String(),
            ],
        );
    }
}
