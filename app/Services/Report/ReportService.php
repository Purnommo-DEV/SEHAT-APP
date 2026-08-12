<?php

namespace App\Services\Report;

use App\Data\EventReportSnapshot;
use App\Enums\ParticipantServiceStatus;
use App\Enums\ParticipantServiceType;
use App\Enums\ParticipantStatus;
use App\Enums\QueueType;
use App\Enums\ScreeningResult;
use App\Enums\ServicePostBehavior;
use App\Models\DonorScreening;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventParticipantService;
use App\Models\HealthAssessment;
use App\Models\QueueTicket;
use App\Models\ServicePost;
use App\Models\ServicePostSubmission;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class ReportService
{
    public function resolveEvent(?int $eventId): ?Event
    {
        if ($eventId !== null) {
            return Event::query()->with('settings')->findOrFail($eventId);
        }

        return Event::query()
            ->with('settings')
            ->orderByRaw("case when active_marker = 'active' then 0 else 1 end")
            ->orderByDesc('starts_at')
            ->first();
    }

    public function snapshot(Event $event): EventReportSnapshot
    {
        $event->loadMissing('settings');
        $participants = EventParticipant::query()
            ->where('event_id', $event->id)
            ->with([
                'participant',
                'services',
                'currentServicePost',
                'healthAssessments.servicePost',
                'donorScreenings.servicePost',
                'donorScreenings.screenedBy',
                'queueTickets.event.settings',
                'queueTickets.eventParticipant.participant',
                'queueTickets.servicePost',
                'servicePostSubmissions.servicePost',
                'servicePostSubmissions.completedBy',
            ])
            ->orderBy('checked_in_at')
            ->orderBy('id')
            ->get();
        $statusCounts = $participants
            ->groupBy(fn (EventParticipant $participant): string => $participant->status->value)
            ->map->count();
        $records = $participants->map(
            fn (EventParticipant $participant): array => $this->record($event, $participant)
        )->all();
        $selectedDonor = $participants->filter(
            fn (EventParticipant $participant): bool => $this->hasService($participant, ParticipantServiceType::Donor),
        )->count();
        $selectedHealthCheck = $participants->filter(
            fn (EventParticipant $participant): bool => $this->hasService($participant, ParticipantServiceType::HealthCheck),
        )->count();
        $selectedBoth = $participants->filter(
            fn (EventParticipant $participant): bool => $this->hasService($participant, ParticipantServiceType::Donor)
                && $this->hasService($participant, ParticipantServiceType::HealthCheck),
        )->count();
        $selectedDonorOnly = $selectedDonor - $selectedBoth;
        $selectedHealthOnly = $selectedHealthCheck - $selectedBoth;
        $screeningResultCounts = $participants
            ->map(
                fn (EventParticipant $participant): ?string => $this
                    ->latestDonorScreening($participant)
                    ?->result
                    ->value,
            )
            ->filter()
            ->countBy();
        $eligibleDonor = (int) $screeningResultCounts->get(ScreeningResult::Eligible->value, 0);
        $notEligibleDonor = (int) $screeningResultCounts->get(ScreeningResult::NotEligible->value, 0);
        $donor = $participants->filter(function (EventParticipant $participant): bool {
            return in_array($this->service($participant, ParticipantServiceType::Donor)?->status, [
                ParticipantServiceStatus::WaitingDonation,
                ParticipantServiceStatus::DonationInProgress,
                ParticipantServiceStatus::Completed,
            ], true);
        })->count();
        $healthCheck = $participants->filter(function (EventParticipant $participant): bool {
            return in_array($this->service($participant, ParticipantServiceType::HealthCheck)?->status, [
                ParticipantServiceStatus::WaitingHealthCheck,
                ParticipantServiceStatus::HealthCheckInProgress,
                ParticipantServiceStatus::Completed,
            ], true);
        })->count();

        return new EventReportSnapshot(
            event: $event,
            metrics: [
                'total_participants' => $participants->count(),
                'checked_in' => $participants->whereNotNull('checked_in_at')->count(),
                'selected_donor' => $selectedDonor,
                'selected_health_check' => $selectedHealthCheck,
                'selected_both' => $selectedBoth,
                'selected_donor_only' => $selectedDonorOnly,
                'selected_health_only' => $selectedHealthOnly,
                'donor' => $donor,
                'health_check' => $healthCheck,
                'eligible_donor' => $eligibleDonor,
                'not_eligible_donor' => $notEligibleDonor,
                'donor_completed' => $this->serviceStatusCount(
                    $participants,
                    ParticipantServiceType::Donor,
                    ParticipantServiceStatus::Completed,
                ),
                'health_check_completed' => $this->serviceStatusCount(
                    $participants,
                    ParticipantServiceType::HealthCheck,
                    ParticipantServiceStatus::Completed,
                ),
                'waiting_service' => $statusCounts->get(ParticipantStatus::WaitingService->value, 0),
                'service_in_progress' => $statusCounts->get(ParticipantStatus::ServiceInProgress->value, 0),
                'finished' => $statusCounts->get(ParticipantStatus::Finished->value, 0),
                'not_eligible' => $statusCounts->get(ParticipantStatus::NotEligible->value, 0),
                'waiting' => $statusCounts->get(ParticipantStatus::Waiting->value, 0),
                'health_check_stage' => $statusCounts->get(ParticipantStatus::HealthCheck->value, 0),
                'donating' => $statusCounts->get(ParticipantStatus::Donating->value, 0),

                // Compatibility metrics are retained for previously exported legacy events.
                'waiting_health' => $statusCounts->get(ParticipantStatus::WaitingHealth->value, 0),
                'health_in_progress' => $statusCounts->get(ParticipantStatus::HealthInProgress->value, 0),
                'waiting_screening' => $statusCounts->get(ParticipantStatus::WaitingScreening->value, 0),
                'waiting_donor' => $statusCounts->get(ParticipantStatus::WaitingDonor->value, 0),
                'donation_completed' => $statusCounts->get(ParticipantStatus::DonationCompleted->value, 0),
            ],
            records: array_values($records),
        );
    }

    public function filename(EventReportSnapshot $snapshot, string $extension): string
    {
        return 'laporan-'.Str::slug($snapshot->event->code).'-'.now()->format('Ymd-His').'.'.$extension;
    }

    /**
     * @return array<string, mixed>
     */
    private function record(Event $event, EventParticipant $participant): array
    {
        $queues = $participant->queueTickets
            ->sortBy(function (QueueTicket $ticket): string {
                $post = $ticket->getRelation('servicePost');
                $sequence = $post instanceof ServicePost ? $post->sequence : PHP_INT_MAX;

                return str_pad((string) $sequence, 10, '0', STR_PAD_LEFT)
                    .str_pad((string) $ticket->number, 10, '0', STR_PAD_LEFT)
                    .str_pad((string) $ticket->id, 10, '0', STR_PAD_LEFT);
            })
            ->values();
        $generalTicket = $participant->queueTickets
            ->first(fn (QueueTicket $ticket): bool => $ticket->queue_type === QueueType::General);
        $donorTicket = $participant->queueTickets
            ->first(fn (QueueTicket $ticket): bool => $ticket->servicePost->behavior === ServicePostBehavior::DonationForm)
            ?? $participant->queueTickets
                ->first(fn (QueueTicket $ticket): bool => $ticket->queue_type !== QueueType::General);
        $healthAssessments = $participant->healthAssessments->keyBy('service_post_id');
        $screenings = $participant->donorScreenings->keyBy('service_post_id');
        $submissions = $participant->servicePostSubmissions->keyBy('service_post_id');
        $healthAssessment = $participant->healthAssessments->sortByDesc('created_at')->first();
        $donorScreening = $this->latestDonorScreening($participant);
        $donorService = $this->service($participant, ParticipantServiceType::Donor);
        $healthCheckService = $this->service($participant, ParticipantServiceType::HealthCheck);
        $serviceHistory = $queues
            ->map(fn (QueueTicket $ticket): array => $this->serviceHistory(
                $ticket,
                $healthAssessments->get($ticket->service_post_id),
                $screenings->get($ticket->service_post_id),
                $submissions->get($ticket->service_post_id),
            ))
            ->all();

        return [
            'id' => $participant->id,
            'registration_number' => $event->settings === null
                ? null
                : $participant->formattedRegistrationNumber($event->settings),
            'name' => $participant->participant->name,
            'nik' => $participant->participant->nik,
            'phone' => $participant->participant->phone,
            'gender' => $participant->participant->gender->label(),
            'status' => $participant->status->value,
            'status_label' => $participant->status->label(),
            'selected_services' => $participant->services
                ->map(fn (EventParticipantService $service): string => $service->service->label())
                ->values()
                ->all(),
            'selected_services_text' => $participant->services
                ->map(fn (EventParticipantService $service): string => $service->service->label())
                ->implode(' + '),
            'donor_service_status' => $donorService?->status->value,
            'donor_service_status_label' => $donorService?->status->label(),
            'health_check_status' => $healthCheckService?->status->value,
            'health_check_status_label' => $healthCheckService?->status->label(),
            'current_post' => $this->currentPostName($participant),
            'checked_in_at' => $participant->checked_in_at?->toIso8601String(),
            'service_history' => array_values($serviceHistory),
            'queue_history_text' => $queues
                ->map(fn (QueueTicket $ticket): string => $ticket->formattedNumber())
                ->implode(' · '),
            'service_history_text' => collect($serviceHistory)
                ->map(fn (array $history): string => $history['post_name'].' ('.$history['queue_status_label'].')')
                ->implode(' · '),

            // Legacy columns stay available for integrations that still consume them.
            'general_queue_number' => $generalTicket?->formattedNumber(),
            'donor_queue_number' => $donorTicket?->formattedNumber(),
            'blood_pressure' => $healthAssessment?->blood_pressure,
            'blood_sugar' => $healthAssessment?->blood_sugar,
            'cholesterol' => $healthAssessment?->cholesterol,
            'uric_acid' => $healthAssessment?->uric_acid,
            'health_notes' => $healthAssessment?->notes,
            'screening_result' => $donorScreening?->result->label(),
            'screening_reason' => $donorScreening?->reason,
            'screened_by' => $donorScreening?->screenedBy?->name,
            'donation_finished_at' => $donorTicket?->finished_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceHistory(
        QueueTicket $ticket,
        ?HealthAssessment $healthAssessment,
        ?DonorScreening $screening,
        ?ServicePostSubmission $submission,
    ): array {
        $post = $ticket->getRelation('servicePost');
        $postName = $post instanceof ServicePost ? $post->name : 'Pos pelayanan';
        $behavior = $post instanceof ServicePost ? $post->behavior : null;
        $details = match (true) {
            $healthAssessment instanceof HealthAssessment => $this->healthSummary($healthAssessment),
            $screening instanceof DonorScreening => $this->screeningSummary($screening),
            $submission instanceof ServicePostSubmission => $this->submissionSummary($submission),
            default => null,
        };

        return [
            'ticket_id' => $ticket->id,
            'service_post_id' => $ticket->service_post_id,
            'post_name' => $postName,
            'behavior' => $behavior?->value,
            'behavior_label' => $behavior?->label(),
            'queue_number' => $ticket->formattedNumber(),
            'queue_status' => $ticket->status->value,
            'queue_status_label' => $ticket->status->label(),
            'completed_at' => $ticket->finished_at?->toIso8601String()
                ?? $submission?->completed_at?->toIso8601String()
                ?? $healthAssessment?->created_at?->toIso8601String()
                ?? $screening?->created_at?->toIso8601String(),
            'details' => $details,
        ];
    }

    private function healthSummary(HealthAssessment $assessment): ?string
    {
        return collect([
            $assessment->blood_pressure === null ? null : 'TD '.$assessment->blood_pressure,
            $assessment->blood_sugar === null ? null : 'Gula '.$assessment->blood_sugar,
            $assessment->cholesterol === null ? null : 'Kolesterol '.$assessment->cholesterol,
            $assessment->uric_acid === null ? null : 'Asam urat '.$assessment->uric_acid,
            $assessment->notes,
        ])->filter()->implode(' · ') ?: null;
    }

    private function screeningSummary(DonorScreening $screening): string
    {
        return collect([$screening->result->label(), $screening->reason])
            ->filter()
            ->implode(' · ');
    }

    private function submissionSummary(ServicePostSubmission $submission): ?string
    {
        $notes = $submission->payload['notes'] ?? null;

        return is_string($notes) && $notes !== '' ? $notes : null;
    }

    private function hasService(EventParticipant $participant, ParticipantServiceType $type): bool
    {
        return $this->service($participant, $type) !== null;
    }

    private function service(
        EventParticipant $participant,
        ParticipantServiceType $type,
    ): ?EventParticipantService {
        $service = $participant->services->first(
            fn (EventParticipantService $selected): bool => $selected->service === $type,
        );

        return $service instanceof EventParticipantService ? $service : null;
    }

    private function latestDonorScreening(EventParticipant $participant): ?DonorScreening
    {
        $screening = $participant->donorScreenings
            ->sortByDesc('id')
            ->first();

        return $screening instanceof DonorScreening ? $screening : null;
    }

    /**
     * @param  Collection<int, EventParticipant>  $participants
     */
    private function serviceStatusCount(
        Collection $participants,
        ParticipantServiceType $type,
        ParticipantServiceStatus $status,
    ): int {
        return $participants->filter(function (EventParticipant $participant) use ($type, $status): bool {
            return $this->service($participant, $type)?->status === $status;
        })->count();
    }

    private function currentPostName(EventParticipant $participant): string
    {
        $post = $participant->getRelation('currentServicePost');

        return $post instanceof ServicePost ? $post->name : '—';
    }
}
