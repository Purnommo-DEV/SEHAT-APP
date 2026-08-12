<?php

namespace App\Data;

readonly class EventQueueResetSummary
{
    public function __construct(
        public int $eventParticipants,
        public int $queueTickets,
        public int $statusHistories,
        public int $participantServices,
        public int $healthAssessments,
        public int $donorScreenings,
        public int $servicePostSubmissions,
        public int $activeDonors,
    ) {}

    public function isEmpty(): bool
    {
        return $this->eventParticipants === 0
            && $this->queueTickets === 0
            && $this->statusHistories === 0
            && $this->participantServices === 0
            && $this->healthAssessments === 0
            && $this->donorScreenings === 0
            && $this->servicePostSubmissions === 0;
    }

    /**
     * @return array{
     *     event_participants: int,
     *     queue_tickets: int,
     *     status_histories: int,
     *     participant_services: int,
     *     health_assessments: int,
     *     donor_screenings: int,
     *     service_post_submissions: int,
     *     active_donors: int,
     *     is_empty: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'event_participants' => $this->eventParticipants,
            'queue_tickets' => $this->queueTickets,
            'status_histories' => $this->statusHistories,
            'participant_services' => $this->participantServices,
            'health_assessments' => $this->healthAssessments,
            'donor_screenings' => $this->donorScreenings,
            'service_post_submissions' => $this->servicePostSubmissions,
            'active_donors' => $this->activeDonors,
            'is_empty' => $this->isEmpty(),
        ];
    }
}
