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
        $event = Event::query()
            ->select(['id', 'code', 'name', 'location'])
            ->active()
            ->with('settings:id,event_id,registration_number_format,registration_queue_prefix,registration_male_prefix,registration_female_prefix,registration_queue_digits,donation_capacity_male,donation_capacity_female')
            ->first();

        if (! $event instanceof Event) {
            return ['event' => null, 'queues' => [], 'donation_capacity' => null];
        }

        $capacity = $this->donationCapacity->snapshot($event);

        $waitingPost = ServicePost::query()
            ->select(['id', 'event_id', 'behavior', 'sequence'])
            ->where('event_id', $event->id)
            ->where('behavior', ServicePostBehavior::HealthForm->value)
            ->where('is_active', true)
            ->orderBy('sequence')
            ->first();

        $tickets = $waitingPost === null
            ? collect()
            : QueueTicket::query()
                ->select([
                    'queue_tickets.id',
                    'queue_tickets.event_id',
                    'queue_tickets.event_participant_id',
                    'queue_tickets.service_post_id',
                    'queue_tickets.queue_type',
                    'queue_tickets.number',
                    'queue_tickets.status',
                ])
                ->join('event_participants', 'event_participants.id', '=', 'queue_tickets.event_participant_id')
                ->where('queue_tickets.event_id', $event->id)
                ->where('queue_tickets.service_post_id', $waitingPost->id)
                ->whereIn('queue_tickets.status', [
                    QueueTicketStatus::Waiting->value,
                    QueueTicketStatus::Calling->value,
                    QueueTicketStatus::Serving->value,
                    QueueTicketStatus::Skipped->value,
                ])
                ->with([
                    'eventParticipant:id,event_id,participant_id,registration_number,registration_order,status',
                    'eventParticipant.participant:id,name,gender',
                    'eventParticipant.services:id,event_participant_id,service',
                ])
                ->orderByRaw("case queue_tickets.status when 'calling' then 0 when 'serving' then 1 when 'waiting' then 2 else 3 end")
                ->orderBy('event_participants.registration_order')
                ->orderBy('event_participants.checked_in_at')
                ->orderBy('event_participants.id')
                ->get();

        $activeParticipantsQuery = EventParticipant::query()
            ->select([
                'id',
                'event_id',
                'participant_id',
                'registration_number',
                'registration_order',
                'status',
            ])
            ->where('event_id', $event->id)
            ->whereIn('status', [
                ParticipantStatus::Calling->value,
                ParticipantStatus::HealthCheck->value,
                ParticipantStatus::WaitingScreening->value,
                ParticipantStatus::Donating->value,
            ])
            ->with([
                'participant:id,name,gender',
                'services:id,event_participant_id,service',
            ]);

        if ($waitingPost instanceof ServicePost) {
            $activeParticipantsQuery->selectSub(
                QueueTicket::query()
                    ->select('called_at')
                    ->whereColumn('queue_tickets.event_participant_id', 'event_participants.id')
                    ->where('queue_tickets.event_id', $event->id)
                    ->where('queue_tickets.service_post_id', $waitingPost->id)
                    ->whereNotNull('queue_tickets.called_at')
                    ->orderByDesc('queue_tickets.called_at')
                    ->orderByDesc('queue_tickets.id')
                    ->limit(1),
                'last_called_at',
            );
        } else {
            $activeParticipantsQuery->selectRaw('NULL AS last_called_at');
        }

        $activeParticipants = $activeParticipantsQuery
            ->orderByDesc('last_called_at')
            ->orderByDesc('id')
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
        $currentPosition = $activeParticipants
            ->filter(fn (EventParticipant $participant): bool => $participant->participant->gender === $gender)
            ->first(fn (EventParticipant $participant): bool => $participant->getAttribute('last_called_at') !== null);
        $waiting = $lane
            ->filter(fn (QueueTicket $ticket): bool => $ticket->status === QueueTicketStatus::Waiting)
            ->sortBy(fn (QueueTicket $ticket): int => $ticket->eventParticipant->registration_order ?? PHP_INT_MAX)
            ->take(5)
            ->values();

        return [
            'id' => $gender->value,
            'label' => $gender->label(),
            'behavior' => 'operational_lane',
            'behavior_label' => 'Area Tunggu',
            'current' => $currentPosition instanceof EventParticipant
                ? $this->positionAsCurrent($currentPosition, $settings)
                : null,
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
                ? $this->instructionFor($ticket->eventParticipant)
                : 'Menunggu panggilan petugas',
        ];
    }

    /** @return array<string, mixed> */
    private function positionAsCurrent(EventParticipant $participant, EventSetting $settings): array
    {
        return [
            'id' => $participant->id,
            'number' => $participant->formattedRegistrationNumber($settings) ?? '-',
            'registration_order' => $participant->registration_order,
            'participant_name' => $participant->participant->name,
            'participant_gender' => $participant->participant->gender->value,
            'participant_gender_label' => $participant->participant->gender->label(),
            'service_name' => $this->targetLabel($participant),
            'status' => $participant->status->value,
            'status_label' => $participant->status->label(),
            'instruction' => $this->instructionFor($participant),
        ];
    }

    /** @return array<string, mixed> */
    private function positionSnapshot(EventParticipant $participant, EventSetting $settings): array
    {
        return [
            'id' => $participant->id,
            'number' => $participant->formattedRegistrationNumber($settings) ?? '-',
            'registration_order' => $participant->registration_order,
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
        return match ($participant->status) {
            ParticipantStatus::Calling => $participant->services->contains(
                fn (EventParticipantService $service): bool => $service->service->value === 'donor',
            ) ? 'Cek Kelayakan Donor' : 'Cek Kesehatan',
            ParticipantStatus::HealthCheck => 'Cek Kesehatan',
            ParticipantStatus::WaitingScreening => 'Cek Kelayakan Donor',
            ParticipantStatus::Donating => 'Sedang Donor',
            default => $participant->status->label(),
        };
    }

    private function instructionFor(EventParticipant $participant): string
    {
        return match ($participant->status) {
            ParticipantStatus::Donating => 'Silakan menuju proses donor',
            ParticipantStatus::Calling,
            ParticipantStatus::HealthCheck,
            ParticipantStatus::WaitingScreening => 'Silakan menuju '.$this->targetLabel($participant),
            default => 'Posisi saat ini: '.$participant->status->label(),
        };
    }
}
