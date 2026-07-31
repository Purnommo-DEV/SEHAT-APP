<?php

namespace App\Services\Dashboard;

use App\Enums\ParticipantServiceStatus;
use App\Enums\ParticipantServiceType;
use App\Enums\ParticipantStatus;
use App\Enums\QueueTicketStatus;
use App\Enums\ScreeningResult;
use App\Enums\ServicePostBehavior;
use App\Models\AuditLog;
use App\Models\DonorScreening;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventParticipantService;
use App\Models\HealthAssessment;
use App\Models\Participant;
use App\Models\QueueTicket;
use App\Models\ServicePost;
use App\Models\ServicePostSubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class DashboardService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $event = Event::query()
            ->active()
            ->with([
                'servicePosts' => fn ($query) => $query
                    ->where('is_active', true)
                    ->whereIn('behavior', [
                        ServicePostBehavior::ScreeningForm->value,
                        ServicePostBehavior::DonationForm->value,
                        ServicePostBehavior::HealthForm->value,
                    ]),
            ])
            ->first();

        if ($event === null) {
            return [
                'event' => null,
                'metrics' => $this->emptyMetrics(),
                'post_metrics' => [],
                'current_positions' => [],
                'activities' => [],
            ];
        }

        $participantSummary = EventParticipant::query()
            ->where('event_id', $event->id)
            ->selectRaw('COUNT(*) as total_participants')
            ->selectRaw('SUM(CASE WHEN checked_in_at IS NOT NULL THEN 1 ELSE 0 END) as checked_in')
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->get();
        $statusCounts = $participantSummary->mapWithKeys(
            fn (EventParticipant $summary): array => [
                $summary->status->value => (int) $summary->getAttribute('total'),
            ],
        );
        $totalParticipants = (int) $participantSummary->sum('total');
        $checkedIn = (int) $participantSummary->sum('checked_in');
        $serviceMetrics = EventParticipantService::query()
            ->where('event_participant_services.event_id', $event->id)
            ->selectRaw(
                <<<'SQL'
                    COALESCE(SUM(CASE WHEN service = ? THEN 1 ELSE 0 END), 0) AS selected_donor,
                    COALESCE(SUM(CASE WHEN service = ? THEN 1 ELSE 0 END), 0) AS selected_health_check,
                    COALESCE(SUM(CASE
                        WHEN service = ? AND EXISTS (
                            SELECT 1
                            FROM event_participant_services AS health_service
                            WHERE health_service.event_participant_id = event_participant_services.event_participant_id
                                AND health_service.service = ?
                        )
                        THEN 1 ELSE 0
                    END), 0) AS selected_both,
                    (SELECT COUNT(*)
                        FROM donor_screenings AS donor_screening
                        WHERE donor_screening.event_id = ?
                            AND donor_screening.result = ?
                            AND donor_screening.id = (
                                SELECT MAX(latest_donor_screening.id)
                                FROM donor_screenings AS latest_donor_screening
                                WHERE latest_donor_screening.event_id = donor_screening.event_id
                                    AND latest_donor_screening.event_participant_id = donor_screening.event_participant_id
                            )
                    ) AS eligible_donor,
                    (SELECT COUNT(*)
                        FROM donor_screenings AS donor_screening
                        WHERE donor_screening.event_id = ?
                            AND donor_screening.result = ?
                            AND donor_screening.id = (
                                SELECT MAX(latest_donor_screening.id)
                                FROM donor_screenings AS latest_donor_screening
                                WHERE latest_donor_screening.event_id = donor_screening.event_id
                                    AND latest_donor_screening.event_participant_id = donor_screening.event_participant_id
                            )
                    ) AS not_eligible_donor,
                    COALESCE(SUM(CASE
                        WHEN service = ? AND status IN (?, ?, ?)
                        THEN 1 ELSE 0
                    END), 0) AS donor,
                    COALESCE(SUM(CASE
                        WHEN service = ? AND status IN (?, ?, ?)
                        THEN 1 ELSE 0
                    END), 0) AS health_check,
                    COALESCE(SUM(CASE WHEN service = ? AND status = ? THEN 1 ELSE 0 END), 0) AS donation_in_progress,
                    COALESCE(SUM(CASE WHEN service = ? AND status = ? THEN 1 ELSE 0 END), 0) AS donor_completed,
                    COALESCE(SUM(CASE WHEN service = ? AND status = ? THEN 1 ELSE 0 END), 0) AS health_check_in_progress,
                    COALESCE(SUM(CASE WHEN service = ? AND status = ? THEN 1 ELSE 0 END), 0) AS health_check_completed
                SQL,
                [
                    ParticipantServiceType::Donor->value,
                    ParticipantServiceType::HealthCheck->value,
                    ParticipantServiceType::Donor->value,
                    ParticipantServiceType::HealthCheck->value,
                    $event->id,
                    ScreeningResult::Eligible->value,
                    $event->id,
                    ScreeningResult::NotEligible->value,
                    ParticipantServiceType::Donor->value,
                    ParticipantServiceStatus::WaitingDonation->value,
                    ParticipantServiceStatus::DonationInProgress->value,
                    ParticipantServiceStatus::Completed->value,
                    ParticipantServiceType::HealthCheck->value,
                    ParticipantServiceStatus::WaitingHealthCheck->value,
                    ParticipantServiceStatus::HealthCheckInProgress->value,
                    ParticipantServiceStatus::Completed->value,
                    ParticipantServiceType::Donor->value,
                    ParticipantServiceStatus::DonationInProgress->value,
                    ParticipantServiceType::Donor->value,
                    ParticipantServiceStatus::Completed->value,
                    ParticipantServiceType::HealthCheck->value,
                    ParticipantServiceStatus::HealthCheckInProgress->value,
                    ParticipantServiceType::HealthCheck->value,
                    ParticipantServiceStatus::Completed->value,
                ],
            )
            ->firstOrFail();
        $ticketCounts = QueueTicket::query()
            ->where('event_id', $event->id)
            ->whereIn('status', [
                QueueTicketStatus::Waiting->value,
                QueueTicketStatus::Calling->value,
                QueueTicketStatus::Serving->value,
            ])
            ->selectRaw('service_post_id, status, COUNT(*) as total')
            ->groupBy('service_post_id', 'status')
            ->get()
            ->groupBy('service_post_id');
        $participants = EventParticipant::query()
            ->where('event_id', $event->id)
            ->whereNotNull('current_service_post_id')
            ->select(['id', 'participant_id', 'current_service_post_id', 'status', 'updated_at'])
            ->with(['participant:id,name,phone,gender', 'currentServicePost:id,name', 'services:id,event_participant_id,service,status'])
            ->latest('updated_at')
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
                'total_participants' => $totalParticipants,
                'checked_in' => $checkedIn,
                'selected_donor' => (int) $serviceMetrics->getAttribute('selected_donor'),
                'selected_health_check' => (int) $serviceMetrics->getAttribute('selected_health_check'),
                'selected_both' => (int) $serviceMetrics->getAttribute('selected_both'),
                'donor' => (int) $serviceMetrics->getAttribute('donor'),
                'health_check' => (int) $serviceMetrics->getAttribute('health_check'),
                'eligible_donor' => (int) $serviceMetrics->getAttribute('eligible_donor'),
                'not_eligible_donor' => (int) $serviceMetrics->getAttribute('not_eligible_donor'),
                'donation_in_progress' => (int) $serviceMetrics->getAttribute('donation_in_progress'),
                'donor_completed' => (int) $serviceMetrics->getAttribute('donor_completed'),
                'health_check_in_progress' => (int) $serviceMetrics->getAttribute('health_check_in_progress'),
                'health_check_completed' => (int) $serviceMetrics->getAttribute('health_check_completed'),
                'waiting_service' => (int) $statusCounts->get(ParticipantStatus::WaitingService->value, 0),
                'service_in_progress' => (int) $statusCounts->get(ParticipantStatus::ServiceInProgress->value, 0),
                'finished' => (int) $statusCounts->get(ParticipantStatus::Finished->value, 0),
                'waiting_health' => (int) $statusCounts->get(ParticipantStatus::WaitingHealth->value, 0),
                'health_in_progress' => (int) $statusCounts->get(ParticipantStatus::HealthInProgress->value, 0),
                'waiting_screening' => (int) $statusCounts->get(ParticipantStatus::WaitingScreening->value, 0),
                'waiting_donor' => (int) $statusCounts->get(ParticipantStatus::WaitingDonor->value, 0),
                'donation_completed' => (int) $statusCounts->get(ParticipantStatus::DonationCompleted->value, 0),
                'not_eligible' => (int) $statusCounts->get(ParticipantStatus::NotEligible->value, 0),
            ],
            'post_metrics' => $event->servicePosts
                ->map(function (ServicePost $post) use ($ticketCounts): array {
                    $counts = $ticketCounts->get($post->id, collect())->mapWithKeys(
                        fn (QueueTicket $summary): array => [
                            $summary->status->value => (int) $summary->getAttribute('total'),
                        ],
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
                })
                ->values()
                ->all(),
            'current_positions' => $this->currentPositions($participants),
            'activities' => $this->activities($event),
        ];
    }

    /**
     * @param  Collection<int, EventParticipant>  $participants
     * @return list<array<string, mixed>>
     */
    private function currentPositions(Collection $participants): array
    {
        $positions = $participants
            ->map(fn (EventParticipant $participant): array => [
                'id' => $participant->id,
                'participant_name' => $participant->participant->name,
                'participant_gender' => $participant->participant->gender->value,
                'participant_gender_label' => $participant->participant->gender->label(),
                'post_name' => $this->currentPostName($participant),
                'status' => $participant->status->value,
                'status_label' => $participant->status->label(),
                'services' => $participant->services
                    ->map(fn (EventParticipantService $service): string => $service->service->label())
                    ->values()
                    ->all(),
                'updated_at' => $participant->updated_at?->toIso8601String(),
            ])
            ->all();

        return array_values($positions);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activities(Event $event): array
    {
        $activities = AuditLog::query()
            ->where('event_id', $event->id)
            ->with(['user', 'subject'])
            ->latest('created_at')
            ->limit(30)
            ->get();

        $activities->loadMorph('subject', [
            EventParticipant::class => ['participant'],
            HealthAssessment::class => ['eventParticipant.participant'],
            DonorScreening::class => ['eventParticipant.participant'],
            QueueTicket::class => ['eventParticipant.participant'],
            ServicePostSubmission::class => ['eventParticipant.participant', 'servicePost'],
            Participant::class => [],
            ServicePost::class => [],
            Event::class => [],
        ]);

        $items = $activities
            ->map(fn (AuditLog $activity): array => [
                'id' => $activity->id,
                'action' => $activity->action->value,
                'action_label' => $activity->action->label(),
                'subject_name' => $this->subjectName($activity),
                'user_name' => $this->activityUserName($activity),
                'created_at' => $activity->created_at->toIso8601String(),
            ])
            ->all();

        return array_values($items);
    }

    private function currentPostName(EventParticipant $participant): string
    {
        $post = $participant->getRelation('currentServicePost');

        return $post instanceof ServicePost ? $post->name : 'Menunggu penempatan';
    }

    private function activityUserName(AuditLog $activity): string
    {
        $user = $activity->getRelation('user');

        return $user instanceof User ? $user->name : 'Sistem';
    }

    private function subjectName(AuditLog $activity): string
    {
        $subject = $activity->subject;

        return match (true) {
            $subject instanceof EventParticipant => $subject->participant->name,
            $subject instanceof HealthAssessment => $subject->eventParticipant->participant->name,
            $subject instanceof DonorScreening => $subject->eventParticipant->participant->name,
            $subject instanceof QueueTicket => $subject->eventParticipant->participant->name,
            $subject instanceof ServicePostSubmission => $subject->eventParticipant->participant->name,
            $subject instanceof Participant => $subject->name,
            $subject instanceof ServicePost => $subject->name,
            $subject instanceof Event => $subject->name,
            default => 'Aktivitas operasional',
        };
    }

    /**
     * @return array<string, int>
     */
    private function emptyMetrics(): array
    {
        return [
            'total_participants' => 0,
            'checked_in' => 0,
            'selected_donor' => 0,
            'selected_health_check' => 0,
            'selected_both' => 0,
            'donor' => 0,
            'health_check' => 0,
            'eligible_donor' => 0,
            'not_eligible_donor' => 0,
            'donation_in_progress' => 0,
            'donor_completed' => 0,
            'health_check_in_progress' => 0,
            'health_check_completed' => 0,
            'waiting_service' => 0,
            'service_in_progress' => 0,
            'finished' => 0,
            'waiting_health' => 0,
            'health_in_progress' => 0,
            'waiting_screening' => 0,
            'waiting_donor' => 0,
            'donation_completed' => 0,
            'not_eligible' => 0,
        ];
    }
}
