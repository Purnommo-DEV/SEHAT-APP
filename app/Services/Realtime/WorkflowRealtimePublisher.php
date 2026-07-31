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

        match ($initialBehavior) {
            ServicePostBehavior::ScreeningForm => ParticipantMovedToEligibility::dispatch(
                $participant->event_id,
                $participant->id,
                $ticket->id,
                $ticket->service_post_id,
            ),
            ServicePostBehavior::HealthForm => ParticipantMovedToHealthCheck::dispatch(
                $participant->event_id,
                $participant->id,
                $ticket->id,
                $ticket->service_post_id,
            ),
            default => null,
        };

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
