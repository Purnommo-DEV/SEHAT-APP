<?php

namespace App\Services\Monitor;

use App\Enums\ParticipantGender;
use App\Enums\ParticipantStatus;
use App\Enums\QueueTicketStatus;
use App\Enums\ServicePostBehavior;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventParticipantService;
use App\Models\EventSetting;
use App\Models\QueueTicket;
use App\Models\ServicePost;
use App\Services\Operational\DonationCapacityService;
use Illuminate\Support\Collection;

class MonitorService
{
    public function __construct(
        private readonly DonationCapacityService $donationCapacity,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $event = Event::query()->active()->with('settings')->first();

        if (! $event instanceof Event) {
            return ['event' => null, 'queues' => [], 'donation_capacity' => null];
        }

        $capacity = $this->donationCapacity->snapshot($event);

        $waitingPost = ServicePost::query()
            ->where('event_id', $event->id)
            ->where('behavior', ServicePostBehavior::HealthForm->value)
            ->where('is_active', true)
            ->orderBy('sequence')
            ->first();

        $tickets = $waitingPost === null
            ? collect()
            : QueueTicket::query()
                ->where('event_id', $event->id)
                ->where('service_post_id', $waitingPost->id)
                ->whereIn('status', [
                    QueueTicketStatus::Waiting->value,
                    QueueTicketStatus::Calling->value,
                    QueueTicketStatus::Serving->value,
                    QueueTicketStatus::Skipped->value,
                ])
                ->with([
                    'eventParticipant.participant:id,name,gender',
                    'eventParticipant.services:id,event_participant_id,service',
                    'servicePost',
                ])
                ->orderByRaw("case status when 'calling' then 0 when 'serving' then 1 when 'waiting' then 2 else 3 end")
                ->orderBy('number')
                ->orderBy('id')
                ->get();

        $activeParticipants = EventParticipant::query()
            ->where('event_id', $event->id)
            ->whereIn('status', [
                ParticipantStatus::Calling->value,
                ParticipantStatus::HealthCheck->value,
                ParticipantStatus::Donating->value,
            ])
            ->with([
                'participant:id,name,gender',
                'services:id,event_participant_id,service',
            ])
            ->orderBy('registration_number')
            ->orderBy('id')
            ->get();

        return [
            'event' => [
                'id' => $event->id,
                'code' => $event->code,
                'name' => $event->name,
                'location' => $event->location,
            ],
            'queues' => collect([ParticipantGender::Male, ParticipantGender::Female])
                ->map(fn (ParticipantGender $gender): array => $this->lane(
                    $gender,
                    $tickets,
                    $activeParticipants,
                    $event->settings,
                    $capacity->forGender($gender)->toArray(),
                ))
                ->all(),
            'donation_capacity' => $capacity->toArray(),
        ];
    }

    /**
     * @param  Collection<int, QueueTicket>  $tickets
     * @param  Collection<int, EventParticipant>  $activeParticipants
     * @param  array<string, int|string|bool>  $capacity
     * @return array<string, mixed>
     */
    private function lane(
        ParticipantGender $gender,
        Collection $tickets,
        Collection $activeParticipants,
        EventSetting $settings,
        array $capacity,
    ): array {
        $lane = $tickets->filter(
            fn (QueueTicket $ticket): bool => $ticket->eventParticipant->participant->gender === $gender,
        )->values();
        $current = $lane->first(fn (QueueTicket $ticket): bool => $ticket->status === QueueTicketStatus::Calling);
        $currentPosition = $activeParticipants
            ->filter(fn (EventParticipant $participant): bool => $participant->participant->gender === $gender)
            ->sortByDesc('updated_at')
            ->first();
        $waiting = $lane
            ->filter(fn (QueueTicket $ticket): bool => $ticket->status === QueueTicketStatus::Waiting)
            ->sortBy('number')
            ->take(5)
            ->values();

        return [
            'id' => $gender->value,
            'label' => $gender->label(),
            'behavior' => 'operational_lane',
            'behavior_label' => 'Area Tunggu',
            'current' => $current instanceof QueueTicket
                ? $this->ticketSnapshot($current, $settings)
                : ($currentPosition instanceof EventParticipant ? $this->positionAsCurrent($currentPosition, $settings) : null),
            'waiting' => $waiting->map(
                fn (QueueTicket $ticket): array => $this->ticketSnapshot($ticket, $settings),
            )->values()->all(),
            'waiting_count' => $lane->where('status', QueueTicketStatus::Waiting)->count(),
            'donation_capacity' => $capacity,
            'active_positions' => $activeParticipants
                ->filter(fn (EventParticipant $participant): bool => $participant->participant->gender === $gender)
                ->map(fn (EventParticipant $participant): array => $this->positionSnapshot($participant, $settings))
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function ticketSnapshot(QueueTicket $ticket, EventSetting $settings): array
    {
        $participant = $ticket->eventParticipant;

        return [
            'id' => $participant->id,
            'number' => $participant->formattedRegistrationNumber($settings) ?? $ticket->formattedNumber(),
            'participant_name' => $participant->participant->name,
            'participant_gender' => $participant->participant->gender->value,
            'participant_gender_label' => $participant->participant->gender->label(),
            'service_name' => $this->targetLabel($ticket->eventParticipant),
            'status' => $ticket->status->value,
            'status_label' => $ticket->status->label(),
            'instruction' => $ticket->status === QueueTicketStatus::Calling
                ? 'Silakan menuju '.$this->targetLabel($ticket->eventParticipant)
                : 'Menunggu panggilan petugas',
        ];
    }

    /** @return array<string, mixed> */
    private function positionAsCurrent(EventParticipant $participant, EventSetting $settings): array
    {
        return [
            'id' => $participant->id,
            'number' => $participant->formattedRegistrationNumber($settings) ?? '-',
            'participant_name' => $participant->participant->name,
            'participant_gender' => $participant->participant->gender->value,
            'participant_gender_label' => $participant->participant->gender->label(),
            'service_name' => $this->targetLabel($participant),
            'status' => $participant->status->value,
            'status_label' => $participant->status === ParticipantStatus::Calling
                ? 'Sedang Dipanggil'
                : 'Sedang Diproses',
            'instruction' => 'Posisi saat ini: '.$participant->status->label(),
        ];
    }

    /** @return array<string, mixed> */
    private function positionSnapshot(EventParticipant $participant, EventSetting $settings): array
    {
        return [
            'id' => $participant->id,
            'number' => $participant->formattedRegistrationNumber($settings) ?? '-',
            'participant_name' => $participant->participant->name,
            'participant_gender' => $participant->participant->gender->value,
            'participant_gender_label' => $participant->participant->gender->label(),
            'services' => $participant->services
                ->map(fn (EventParticipantService $service): string => $service->service->label())
                ->values()
                ->all(),
            'position' => $participant->status->value,
            'position_label' => $participant->status->label(),
        ];
    }

    private function targetLabel(EventParticipant $participant): string
    {
        $hasHealthCheck = $participant->services->contains(
            fn (EventParticipantService $service): bool => $service->service->value === 'health_check',
        );

        return match ($participant->status) {
            ParticipantStatus::Calling => $hasHealthCheck ? 'Cek Kesehatan' : 'Donor',
            ParticipantStatus::HealthCheck => 'Cek Kesehatan',
            ParticipantStatus::Donating => 'Donor',
            default => $participant->status->label(),
        };
    }
}
