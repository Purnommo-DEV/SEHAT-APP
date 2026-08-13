<?php

namespace App\Services\Realtime;

use App\Enums\AuditAction;
use App\Enums\ScreeningResult;
use App\Enums\ServicePostBehavior;
use App\Events\DashboardUpdated;
use App\Events\ParticipantDonationCompleted;
use App\Events\ParticipantEligible;
use App\Events\ParticipantHealthCheckCompleted;
use App\Events\ParticipantIneligible;
use App\Events\ParticipantMovedToDonation;
use App\Events\ParticipantMovedToEligibility;
use App\Events\ParticipantMovedToHealthCheck;
use App\Events\ParticipantRegistered;
use App\Events\QueueUpdated;
use App\Events\TVMonitorUpdated;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\QueueTicket;

class WorkflowRealtimePublisher
{
    public function participantRegistered(
        EventParticipant $participant,
        QueueTicket $ticket,
        ServicePostBehavior $initialBehavior,
    ): void {
        ParticipantRegistered::dispatch(
            $participant->event_id,
            $participant->id,
            $ticket->id,
            $ticket->service_post_id,
        );

        $this->snapshots($ticket, 'participant.registered');
    }

    public function serviceCompleted(
        QueueTicket $ticket,
        int $eventParticipantId,
        ServicePostBehavior $completedBehavior,
        ?ScreeningResult $screeningResult,
        ?ServicePostBehavior $nextBehavior,
        ?int $nextServicePostId,
        ?int $nextQueueTicketId,
    ): void {
        $this->serviceCompletedForParticipant(
            $ticket->event_id,
            $eventParticipantId,
            $ticket->id,
            $completedBehavior,
            $screeningResult,
            $nextBehavior,
            $nextServicePostId,
            $nextQueueTicketId,
        );
    }

    public function serviceCompletedForParticipant(
        int $eventId,
        int $eventParticipantId,
        ?int $queueTicketId,
        ServicePostBehavior $completedBehavior,
        ?ScreeningResult $screeningResult,
        ?ServicePostBehavior $nextBehavior,
        ?int $nextServicePostId,
        ?int $nextQueueTicketId,
    ): void {
        if ($completedBehavior === ServicePostBehavior::ScreeningForm) {
            if ($screeningResult === ScreeningResult::Eligible) {
                ParticipantEligible::dispatch(
                    $eventId,
                    $eventParticipantId,
                    $queueTicketId,
                    $nextServicePostId,
                );
                ParticipantMovedToDonation::dispatch(
                    $eventId,
                    $eventParticipantId,
                    $nextQueueTicketId,
                    $nextServicePostId,
                );
            } elseif ($screeningResult === ScreeningResult::NotEligible) {
                ParticipantIneligible::dispatch(
                    $eventId,
                    $eventParticipantId,
                    $queueTicketId,
                    $nextServicePostId,
                );

                if ($nextBehavior === ServicePostBehavior::HealthForm) {
                    ParticipantMovedToHealthCheck::dispatch(
                        $eventId,
                        $eventParticipantId,
                        $nextQueueTicketId,
                        $nextServicePostId,
                    );
                }
            }
        } elseif ($completedBehavior === ServicePostBehavior::DonationForm) {
            ParticipantDonationCompleted::dispatch(
                $eventId,
                $eventParticipantId,
                $queueTicketId,
                $nextServicePostId,
            );

            if ($nextBehavior === ServicePostBehavior::HealthForm) {
                ParticipantMovedToHealthCheck::dispatch(
                    $eventId,
                    $eventParticipantId,
                    $nextQueueTicketId,
                    $nextServicePostId,
                );
            }
        } elseif ($completedBehavior === ServicePostBehavior::HealthForm) {
            ParticipantHealthCheckCompleted::dispatch(
                $eventId,
                $eventParticipantId,
                $queueTicketId,
                null,
            );
        }
    }

    public function queueChanged(QueueTicket $ticket, AuditAction $action): void
    {
        $this->queueStateChanged(
            $ticket->event_id,
            $action,
            $ticket->event_participant_id,
            $ticket->id,
            $ticket->service_post_id,
        );
    }

    public function queueStateChanged(
        int $eventId,
        AuditAction $action,
        ?int $eventParticipantId,
        ?int $queueTicketId,
        ?int $servicePostId,
    ): void {
        $this->snapshotsByIds(
            $eventId,
            $action->value,
            $eventParticipantId,
            $queueTicketId,
            $servicePostId,
        );
    }

    public function movedToEligibility(
        QueueTicket $ticket,
        int $eventParticipantId,
        int $nextServicePostId,
        ?int $nextQueueTicketId,
    ): void {
        ParticipantMovedToEligibility::dispatch(
            $ticket->event_id,
            $eventParticipantId,
            $nextQueueTicketId,
            $nextServicePostId,
        );
    }

    public function movedToHealthCheck(
        QueueTicket $ticket,
        int $eventParticipantId,
        int $nextServicePostId,
        ?int $nextQueueTicketId,
    ): void {
        ParticipantMovedToHealthCheck::dispatch(
            $ticket->event_id,
            $eventParticipantId,
            $nextQueueTicketId,
            $nextServicePostId,
        );
    }

    public function operationalHealthCheckStarted(EventParticipant $participant, ?int $servicePostId): void
    {
        ParticipantMovedToHealthCheck::dispatch(
            $participant->event_id,
            $participant->id,
            null,
            $servicePostId,
        );

        $this->queueStateChanged(
            $participant->event_id,
            AuditAction::ParticipantHealthCheckStarted,
            $participant->id,
            null,
            $servicePostId,
        );
    }

    public function operationalDonationStarted(EventParticipant $participant, QueueTicket $ticket): void
    {
        ParticipantMovedToDonation::dispatch(
            $participant->event_id,
            $participant->id,
            $ticket->id,
            $ticket->service_post_id,
        );

        $this->queueStateChanged(
            $participant->event_id,
            AuditAction::ParticipantMovedToDonation,
            $participant->id,
            $ticket->id,
            $ticket->service_post_id,
        );
    }

    public function operationalMovedToEligibility(EventParticipant $participant, ?int $servicePostId): void
    {
        ParticipantMovedToEligibility::dispatch(
            $participant->event_id,
            $participant->id,
            null,
            $servicePostId,
        );

        $this->queueStateChanged(
            $participant->event_id,
            AuditAction::ParticipantEligibilityStarted,
            $participant->id,
            null,
            $servicePostId,
        );
    }

    public function operationalEligible(
        EventParticipant $participant,
        ?QueueTicket $ticket = null,
        ?int $nextServicePostId = null,
    ): void {
        ParticipantEligible::dispatch(
            $participant->event_id,
            $participant->id,
            $ticket?->id,
            $nextServicePostId ?? $ticket?->service_post_id,
        );

        if ($ticket instanceof QueueTicket) {
            ParticipantMovedToDonation::dispatch(
                $participant->event_id,
                $participant->id,
                $ticket->id,
                $ticket->service_post_id,
            );
        } elseif ($nextServicePostId !== null) {
            ParticipantMovedToHealthCheck::dispatch(
                $participant->event_id,
                $participant->id,
                null,
                $nextServicePostId,
            );
        }

        $this->queueStateChanged(
            $participant->event_id,
            AuditAction::ScreeningEligible,
            $participant->id,
            $ticket?->id,
            $nextServicePostId ?? $ticket?->service_post_id,
        );
    }

    public function operationalIneligible(EventParticipant $participant): void
    {
        ParticipantIneligible::dispatch(
            $participant->event_id,
            $participant->id,
            null,
            null,
        );

        $this->queueStateChanged(
            $participant->event_id,
            AuditAction::ScreeningNotEligible,
            $participant->id,
            null,
            null,
        );
    }

    public function operationalCompleted(EventParticipant $participant, ?QueueTicket $ticket = null): void
    {
        if ($ticket === null) {
            ParticipantHealthCheckCompleted::dispatch(
                $participant->event_id,
                $participant->id,
                null,
                null,
            );
        } else {
            ParticipantDonationCompleted::dispatch(
                $participant->event_id,
                $participant->id,
                $ticket->id,
                null,
            );
        }

        $this->queueStateChanged(
            $participant->event_id,
            AuditAction::ParticipantWorkflowCompleted,
            $participant->id,
            $ticket?->id,
            $ticket?->service_post_id,
        );
    }

    public function donationCapacityUpdated(Event $event): void
    {
        $this->queueStateChanged(
            $event->id,
            AuditAction::DonationCapacityUpdated,
            null,
            null,
            null,
        );
    }

    public function queueReset(Event $event): void
    {
        $this->queueStateChanged(
            $event->id,
            AuditAction::QueueReset,
            null,
            null,
            null,
        );
    }

    private function snapshots(QueueTicket $ticket, string $reason): void
    {
        $this->snapshotsByIds(
            $ticket->event_id,
            $reason,
            $ticket->event_participant_id,
            $ticket->id,
            $ticket->service_post_id,
        );
    }

    private function snapshotsByIds(
        int $eventId,
        string $reason,
        ?int $eventParticipantId,
        ?int $queueTicketId,
        ?int $servicePostId,
    ): void {
        QueueUpdated::dispatch(
            $eventId,
            $reason,
            $eventParticipantId,
            $queueTicketId,
            $servicePostId,
        );
        DashboardUpdated::dispatch(
            $eventId,
            $reason,
            $eventParticipantId,
            $queueTicketId,
            $servicePostId,
        );
        TVMonitorUpdated::dispatch(
            $eventId,
            $reason,
            $eventParticipantId,
            $queueTicketId,
            $servicePostId,
        );
    }
}
