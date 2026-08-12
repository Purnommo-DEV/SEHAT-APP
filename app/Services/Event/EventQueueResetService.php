<?php

namespace App\Services\Event;

use App\Data\EventQueueResetSummary;
use App\Enums\AuditAction;
use App\Enums\ParticipantStatus;
use App\Models\DonorScreening;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventParticipantService;
use App\Models\EventParticipantStatusHistory;
use App\Models\HealthAssessment;
use App\Models\QueueTicket;
use App\Models\ServicePostSubmission;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Realtime\WorkflowRealtimePublisher;
use Illuminate\Database\DatabaseManager;

class EventQueueResetService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly AuditLogger $auditLogger,
        private readonly WorkflowRealtimePublisher $realtimePublisher,
    ) {}

    public function summary(Event $event): EventQueueResetSummary
    {
        return $this->summaryFor($event);
    }

    public function reset(Event $event, User $actor): EventQueueResetSummary
    {
        /** @var EventQueueResetSummary $summary */
        $summary = $this->database->transaction(function () use ($event, $actor): EventQueueResetSummary {
            /**
             * This exclusive lock is the reset gate. Standard queue mutations
             * lock this same row before changing operational data, so reset
             * cannot interleave with a check-in or state transition.
             */
            $lockedEvent = Event::query()
                ->whereKey($event->id)
                ->lockForUpdate()
                ->firstOrFail();

            $summary = $this->summaryFor($lockedEvent, true);

            EventParticipantStatusHistory::query()
                ->where('event_id', $lockedEvent->id)
                ->delete();
            ServicePostSubmission::query()
                ->where('event_id', $lockedEvent->id)
                ->delete();
            HealthAssessment::query()
                ->where('event_id', $lockedEvent->id)
                ->delete();
            DonorScreening::query()
                ->where('event_id', $lockedEvent->id)
                ->delete();
            QueueTicket::query()
                ->where('event_id', $lockedEvent->id)
                ->delete();
            EventParticipantService::query()
                ->where('event_id', $lockedEvent->id)
                ->delete();
            EventParticipant::query()
                ->where('event_id', $lockedEvent->id)
                ->delete();

            // Operational audit rows are intentionally retained. They are a
            // permanent trace, while the Event model remains a valid subject.
            $this->auditLogger->record(
                actor: $actor,
                subject: $lockedEvent,
                action: AuditAction::QueueReset,
                eventId: $lockedEvent->id,
                oldValues: $summary->toArray(),
                newValues: [
                    'queue_tickets_deleted' => $summary->queueTickets,
                    'event_participants_deleted' => $summary->eventParticipants,
                    'status_histories_deleted' => $summary->statusHistories,
                    'participant_services_deleted' => $summary->participantServices,
                    'health_assessments_deleted' => $summary->healthAssessments,
                    'donor_screenings_deleted' => $summary->donorScreenings,
                    'service_post_submissions_deleted' => $summary->servicePostSubmissions,
                    'active_donors_at_reset' => $summary->activeDonors,
                    'reset_at' => now()->toIso8601String(),
                ],
            );

            return $summary;
        });

        if (! $summary->isEmpty()) {
            $this->realtimePublisher->queueReset($event);
        }

        return $summary;
    }

    private function summaryFor(Event $event, bool $lockParticipants = false): EventQueueResetSummary
    {
        if ($lockParticipants) {
            // Lock individual rows with a non-aggregate query. PostgreSQL
            // rejects FOR UPDATE on COUNT(*), while MySQL may silently ignore
            // it, so the lock must be acquired before calculating counts.
            EventParticipant::query()
                ->where('event_id', $event->id)
                ->select('id')
                ->lockForUpdate()
                ->get();
        }

        $participants = EventParticipant::query()->where('event_id', $event->id);

        return new EventQueueResetSummary(
            eventParticipants: (clone $participants)->count(),
            queueTickets: QueueTicket::query()->where('event_id', $event->id)->count(),
            statusHistories: EventParticipantStatusHistory::query()->where('event_id', $event->id)->count(),
            participantServices: EventParticipantService::query()->where('event_id', $event->id)->count(),
            healthAssessments: HealthAssessment::query()->where('event_id', $event->id)->count(),
            donorScreenings: DonorScreening::query()->where('event_id', $event->id)->count(),
            servicePostSubmissions: ServicePostSubmission::query()->where('event_id', $event->id)->count(),
            activeDonors: (clone $participants)
                ->where('status', ParticipantStatus::Donating->value)
                ->count(),
        );
    }
}
