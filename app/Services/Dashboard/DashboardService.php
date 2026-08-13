<?php

namespace App\Services\Dashboard;

use App\Enums\ParticipantServiceType;
use App\Enums\ParticipantStatus;
use App\Enums\QueueTicketStatus;
use App\Enums\ServicePostBehavior;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventParticipantService;
use App\Models\Participant;
use App\Models\QueueTicket;
use App\Models\ServicePost;
use App\Models\User;
use App\Services\Operational\DonationCapacityService;
use Illuminate\Database\Eloquent\Collection;

class DashboardService
{
    public function __construct(
        private readonly DonationCapacityService $donationCapacity,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $event = Event::query()->select([
            'id',
            'code',
            'name',
            'location',
            'starts_at',
        ])->active()->with([
            'settings:id,event_id,registration_number_format,registration_queue_prefix,registration_male_prefix,registration_female_prefix,registration_queue_digits,donation_capacity_male,donation_capacity_female',
            'servicePosts' => fn ($query) => $query->where('is_active', true)
                ->whereIn('behavior', [
                    ServicePostBehavior::HealthForm->value,
                    ServicePostBehavior::DonationForm->value,
                ])->select(['id', 'event_id', 'name', 'behavior']),
        ])->first();

        if (! $event instanceof Event) {
            return [
                'event' => null,
                'metrics' => $this->emptyMetrics(),
                'post_metrics' => [],
                'donation_capacity' => $this->emptyDonationCapacity(),
                'current_positions' => [],
                'activities' => [],
            ];
        }

        $statusCounts = EventParticipant::query()
            ->where('event_id', $event->id)
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->get()
            ->mapWithKeys(fn (EventParticipant $row): array => [$row->status->value => (int) $row->getAttribute('total')]);

        $checkedIn = EventParticipant::query()
            ->where('event_id', $event->id)
            ->whereNotNull('checked_in_at')
            ->count();

        $serviceMetrics = EventParticipantService::query()
            ->where('event_participant_services.event_id', $event->id)
            ->selectRaw(
                <<<'SQL'
                    SUM(CASE WHEN service = ? THEN 1 ELSE 0 END) AS selected_donor,
                    SUM(CASE WHEN service = ? THEN 1 ELSE 0 END) AS selected_health_check,
                    SUM(CASE WHEN service = ? AND EXISTS (
                        SELECT 1 FROM event_participant_services AS health_service
                        WHERE health_service.event_participant_id = event_participant_services.event_participant_id
                          AND health_service.service = ?
                    ) THEN 1 ELSE 0 END) AS selected_both,
                    SUM(CASE WHEN service = ? AND NOT EXISTS (
                        SELECT 1 FROM event_participant_services AS health_service
                        WHERE health_service.event_participant_id = event_participant_services.event_participant_id
                          AND health_service.service = ?
                    ) THEN 1 ELSE 0 END) AS selected_donor_only,
                    SUM(CASE WHEN service = ? AND NOT EXISTS (
                        SELECT 1 FROM event_participant_services AS donor_service
                        WHERE donor_service.event_participant_id = event_participant_services.event_participant_id
                          AND donor_service.service = ?
                    ) THEN 1 ELSE 0 END) AS selected_health_only
                SQL,
                [
                    ParticipantServiceType::Donor->value,
                    ParticipantServiceType::HealthCheck->value,
                    ParticipantServiceType::Donor->value,
                    ParticipantServiceType::HealthCheck->value,
                    ParticipantServiceType::Donor->value,
                    ParticipantServiceType::HealthCheck->value,
                    ParticipantServiceType::HealthCheck->value,
                    ParticipantServiceType::Donor->value,
                ],
            )->first();

        $ticketCounts = QueueTicket::query()
            ->where('event_id', $event->id)
            ->whereIn('status', [QueueTicketStatus::Waiting->value, QueueTicketStatus::Calling->value, QueueTicketStatus::Serving->value])
            ->selectRaw('service_post_id, status, COUNT(*) AS total')
            ->groupBy('service_post_id', 'status')
            ->get()
            ->groupBy('service_post_id');

        $participants = EventParticipant::query()
            ->select([
                'id',
                'event_id',
                'participant_id',
                'current_service_post_id',
                'registration_number',
                'registration_order',
                'status',
                'updated_at',
                'checked_in_at',
            ])
            ->where('event_id', $event->id)
            ->whereIn('status', [
                ParticipantStatus::Waiting->value,
                ParticipantStatus::Calling->value,
                ParticipantStatus::HealthCheck->value,
                ParticipantStatus::WaitingScreening->value,
                ParticipantStatus::Donating->value,
                ParticipantStatus::Finished->value,
            ])
            ->with(['participant:id,name,gender', 'currentServicePost:id,name', 'services:id,event_participant_id,service'])
            ->orderBy('registration_order')
            ->orderBy('checked_in_at')
            ->orderBy('id')
            ->limit(30)
            ->get();

        return [
            'event' => [
                'id' => $event->id,
                'code' => $event->code,
                'name' => $event->name,
                'location' => $event->location,
                'starts_at' => $event->starts_at->toIso8601String(),
            ],
            'metrics' => [
                'total_participants' => $statusCounts->sum(),
                'checked_in' => $checkedIn,
                'donor' => (int) ($serviceMetrics?->getAttribute('selected_donor') ?? 0),
                'health_check' => (int) ($serviceMetrics?->getAttribute('selected_health_check') ?? 0),
                'selected_donor' => (int) ($serviceMetrics?->getAttribute('selected_donor') ?? 0),
                'selected_health_check' => (int) ($serviceMetrics?->getAttribute('selected_health_check') ?? 0),
                'selected_both' => (int) ($serviceMetrics?->getAttribute('selected_both') ?? 0),
                'selected_donor_only' => (int) ($serviceMetrics?->getAttribute('selected_donor_only') ?? 0),
                'selected_health_only' => (int) ($serviceMetrics?->getAttribute('selected_health_only') ?? 0),
                'waiting' => (int) $statusCounts->get(ParticipantStatus::Waiting->value, 0),
                'calling' => (int) $statusCounts->get(ParticipantStatus::Calling->value, 0),
                'health_check_stage' => (int) $statusCounts->get(ParticipantStatus::HealthCheck->value, 0),
                'eligibility' => (int) $statusCounts->get(ParticipantStatus::WaitingScreening->value, 0),
                'donating' => (int) $statusCounts->get(ParticipantStatus::Donating->value, 0),
                'finished' => (int) $statusCounts->get(ParticipantStatus::Finished->value, 0),
            ],
            'post_metrics' => $event->servicePosts->map(function (ServicePost $post) use ($ticketCounts): array {
                $counts = $ticketCounts->get($post->id, collect())->mapWithKeys(
                    fn (QueueTicket $row): array => [$row->status->value => (int) $row->getAttribute('total')],
                );

                return [
                    'id' => $post->id,
                    'name' => $post->name,
                    'behavior' => $post->behavior->value,
                    'behavior_label' => $post->behavior->label(),
                    'waiting' => (int) $counts->get(QueueTicketStatus::Waiting->value, 0),
                    'calling' => (int) $counts->get(QueueTicketStatus::Calling->value, 0),
                    'serving' => (int) $counts->get(QueueTicketStatus::Serving->value, 0),
                ];
            })->values()->all(),
            'donation_capacity' => $this->donationCapacity->snapshot($event)->toArray(),
            'current_positions' => $this->currentPositions($participants, $event),
            'activities' => $this->activities($event),
        ];
    }

    /** @param Collection<int, EventParticipant> $participants
     *  @return list<array<string, mixed>> */
    private function currentPositions(Collection $participants, Event $event): array
    {
        return array_values($participants->map(function (EventParticipant $participant) use ($event): array {
            $post = $participant->getRelation('currentServicePost');

            return [
                'id' => $participant->id,
                'number' => $participant->formattedRegistrationNumber($event->settings),
                'registration_order' => $participant->registration_order,
                'participant_name' => $participant->participant->name,
                'participant_gender' => $participant->participant->gender->value,
                'participant_gender_label' => $participant->participant->gender->label(),
                'post_name' => $post instanceof ServicePost ? $post->name : $participant->status->label(),
                'status' => $participant->status->value,
                'status_label' => $participant->status->label(),
                'services' => $participant->services->map(fn (EventParticipantService $service): string => $service->service->label())->all(),
                'updated_at' => $participant->updated_at?->toIso8601String(),
            ];
        })->all());
    }

    /** @return list<array<string, mixed>> */
    private function activities(Event $event): array
    {
        $activities = AuditLog::query()
            ->select(['id', 'user_id', 'subject_type', 'subject_id', 'action', 'created_at'])
            ->where('event_id', $event->id)
            ->with(['user:id,name'])
            ->latest('created_at')
            ->limit(30)
            ->get();
        $subjectNames = $this->subjectNames($activities);

        return array_values($activities->map(fn (AuditLog $activity): array => [
            'id' => $activity->id,
            'action' => $activity->action->value,
            'action_label' => $activity->action->label(),
            'subject_name' => $subjectNames[$this->subjectKey($activity)] ?? 'Aktivitas operasional',
            'user_name' => $activity->user instanceof User ? $activity->user->name : 'Sistem',
            'created_at' => $activity->created_at->toIso8601String(),
        ])->all());
    }

    /**
     * @param  Collection<int, AuditLog>  $activities
     * @return array<string, string>
     */
    private function subjectNames(Collection $activities): array
    {
        $idsFor = fn (string $type) => $activities
            ->where('subject_type', $type)
            ->pluck('subject_id')
            ->filter()
            ->unique()
            ->values();
        $names = [];

        $eventParticipantIds = $idsFor(EventParticipant::class);

        if ($eventParticipantIds->isNotEmpty()) {
            EventParticipant::query()
                ->select(['id', 'participant_id'])
                ->whereKey($eventParticipantIds)
                ->with('participant:id,name')
                ->get()
                ->each(function (EventParticipant $participant) use (&$names): void {
                    $names[EventParticipant::class.':'.$participant->id] = $participant->participant->name;
                });
        }

        $ticketIds = $idsFor(QueueTicket::class);

        if ($ticketIds->isNotEmpty()) {
            QueueTicket::query()
                ->select(['id', 'event_participant_id'])
                ->with('eventParticipant:id,participant_id')
                ->with('eventParticipant.participant:id,name')
                ->whereKey($ticketIds)
                ->get()
                ->each(function (QueueTicket $ticket) use (&$names): void {
                    $names[QueueTicket::class.':'.$ticket->id] = $ticket->eventParticipant->participant->name;
                });
        }

        $participantIds = $idsFor(Participant::class);

        if ($participantIds->isNotEmpty()) {
            Participant::query()
                ->select(['id', 'name'])
                ->whereKey($participantIds)
                ->get()
                ->each(function (Participant $participant) use (&$names): void {
                    $names[Participant::class.':'.$participant->id] = $participant->name;
                });
        }

        $servicePostIds = $idsFor(ServicePost::class);

        if ($servicePostIds->isNotEmpty()) {
            ServicePost::query()
                ->select(['id', 'name'])
                ->whereKey($servicePostIds)
                ->get()
                ->each(function (ServicePost $post) use (&$names): void {
                    $names[ServicePost::class.':'.$post->id] = $post->name;
                });
        }

        $eventIds = $idsFor(Event::class);

        if ($eventIds->isNotEmpty()) {
            Event::query()
                ->select(['id', 'name'])
                ->whereKey($eventIds)
                ->get()
                ->each(function (Event $subjectEvent) use (&$names): void {
                    $names[Event::class.':'.$subjectEvent->id] = $subjectEvent->name;
                });
        }

        return $names;
    }

    private function subjectKey(AuditLog $activity): string
    {
        return $activity->subject_type.':'.$activity->subject_id;
    }

    /** @return array<string, int> */
    private function emptyMetrics(): array
    {
        return array_fill_keys([
            'total_participants', 'checked_in', 'donor', 'health_check', 'selected_donor',
            'selected_health_check', 'selected_both', 'selected_donor_only', 'selected_health_only',
            'waiting', 'calling', 'health_check_stage', 'eligibility', 'donating', 'finished',
        ], 0);
    }

    /** @return array<string, array<string, int|string|bool>> */
    private function emptyDonationCapacity(): array
    {
        return [
            'male' => $this->emptyDonationCapacityLane('male', 'Laki-laki'),
            'female' => $this->emptyDonationCapacityLane('female', 'Perempuan'),
            'total' => [
                'capacity' => 0,
                'active' => 0,
                'active_donations' => 0,
                'available' => 0,
                'available_slots' => 0,
                'is_full' => false,
            ],
        ];
    }

    /** @return array<string, int|string|bool> */
    private function emptyDonationCapacityLane(string $gender, string $label): array
    {
        return [
            'gender' => $gender,
            'label' => $label,
            'capacity' => 0,
            'active' => 0,
            'active_donations' => 0,
            'available' => 0,
            'available_slots' => 0,
            'is_full' => false,
        ];
    }
}
