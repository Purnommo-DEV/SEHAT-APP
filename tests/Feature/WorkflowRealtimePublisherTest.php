<?php

namespace Tests\Feature;

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
use App\Services\Realtime\WorkflowRealtimePublisher;
use Illuminate\Support\Facades\Event as EventFacade;
use Tests\TestCase;

class WorkflowRealtimePublisherTest extends TestCase
{
    public function test_donor_registration_publishes_registration_eligibility_and_snapshot_events(): void
    {
        EventFacade::fake();

        $this->publisher()->participantRegistered(
            $this->participant(),
            $this->ticket(),
            ServicePostBehavior::ScreeningForm,
        );

        EventFacade::assertDispatched(ParticipantRegistered::class, $this->participantEvent());
        EventFacade::assertDispatched(ParticipantMovedToEligibility::class, $this->participantEvent());
        EventFacade::assertNotDispatched(ParticipantMovedToHealthCheck::class);
        $this->assertSnapshotEvents('participant.registered');
    }

    public function test_health_only_registration_moves_directly_to_health_check(): void
    {
        EventFacade::fake();

        $this->publisher()->participantRegistered(
            $this->participant(),
            $this->ticket(),
            ServicePostBehavior::HealthForm,
        );

        EventFacade::assertDispatched(ParticipantRegistered::class, $this->participantEvent());
        EventFacade::assertDispatched(ParticipantMovedToHealthCheck::class, $this->participantEvent());
        EventFacade::assertNotDispatched(ParticipantMovedToEligibility::class);
        $this->assertSnapshotEvents('participant.registered');
    }

    public function test_eligible_screening_publishes_eligible_and_move_to_donation_events(): void
    {
        EventFacade::fake();

        $this->publisher()->serviceCompleted(
            $this->ticket(),
            52,
            ServicePostBehavior::ScreeningForm,
            ScreeningResult::Eligible,
            ServicePostBehavior::DonationForm,
            62,
            73,
        );

        EventFacade::assertDispatched(ParticipantEligible::class, $this->participantEvent(62));
        EventFacade::assertDispatched(
            ParticipantMovedToDonation::class,
            $this->participantEvent(62, 73),
        );
        EventFacade::assertNotDispatched(ParticipantIneligible::class);
        EventFacade::assertNotDispatched(ParticipantMovedToHealthCheck::class);
    }

    public function test_ineligible_screening_with_health_selection_skips_donation(): void
    {
        EventFacade::fake();

        $this->publisher()->serviceCompleted(
            $this->ticket(),
            52,
            ServicePostBehavior::ScreeningForm,
            ScreeningResult::NotEligible,
            ServicePostBehavior::HealthForm,
            64,
            75,
        );

        EventFacade::assertDispatched(ParticipantIneligible::class, $this->participantEvent(64));
        EventFacade::assertDispatched(
            ParticipantMovedToHealthCheck::class,
            $this->participantEvent(64, 75),
        );
        EventFacade::assertNotDispatched(ParticipantEligible::class);
        EventFacade::assertNotDispatched(ParticipantMovedToDonation::class);
    }

    public function test_donation_completion_with_health_selection_publishes_both_transitions(): void
    {
        EventFacade::fake();

        $this->publisher()->serviceCompleted(
            $this->ticket(),
            52,
            ServicePostBehavior::DonationForm,
            null,
            ServicePostBehavior::HealthForm,
            64,
            75,
        );

        EventFacade::assertDispatched(ParticipantDonationCompleted::class, $this->participantEvent(64));
        EventFacade::assertDispatched(
            ParticipantMovedToHealthCheck::class,
            $this->participantEvent(64, 75),
        );
    }

    public function test_health_completion_publishes_terminal_health_event(): void
    {
        EventFacade::fake();

        $this->publisher()->serviceCompleted(
            $this->ticket(),
            52,
            ServicePostBehavior::HealthForm,
            null,
            null,
            null,
            null,
        );

        EventFacade::assertDispatched(
            ParticipantHealthCheckCompleted::class,
            $this->participantEvent(null),
        );
    }

    public function test_cancelled_donor_flow_identifies_the_new_health_queue_ticket(): void
    {
        EventFacade::fake();

        $this->publisher()->movedToHealthCheck($this->ticket(), 52, 64, 75);

        EventFacade::assertDispatched(
            ParticipantMovedToHealthCheck::class,
            $this->participantEvent(64, 75),
        );
    }

    public function test_legacy_health_transition_can_identify_eligibility_without_a_target_ticket(): void
    {
        EventFacade::fake();

        $this->publisher()->movedToEligibility($this->ticket(), 52, 62, null);

        EventFacade::assertDispatched(
            ParticipantMovedToEligibility::class,
            $this->participantEvent(62, null),
        );
    }

    public function test_every_queue_change_invalidates_queue_dashboard_and_tv_snapshots(): void
    {
        EventFacade::fake();

        $this->publisher()->queueChanged($this->ticket(), AuditAction::QueueTicketCalled);

        $this->assertSnapshotEvents(AuditAction::QueueTicketCalled->value);
    }

    private function publisher(): WorkflowRealtimePublisher
    {
        return app(WorkflowRealtimePublisher::class);
    }

    private function participant(): EventParticipant
    {
        $participant = new EventParticipant(['event_id' => 41]);
        $participant->id = 52;

        return $participant;
    }

    private function ticket(): QueueTicket
    {
        $ticket = new QueueTicket([
            'event_id' => 41,
            'event_participant_id' => 52,
            'service_post_id' => 61,
        ]);
        $ticket->id = 53;

        return $ticket;
    }

    /**
     * @return callable(object): bool
     */
    private function participantEvent(
        ?int $nextServicePostId = 61,
        ?int $queueTicketId = 53,
    ): callable {
        return static fn (object $event): bool => $event->eventId === 41
            && $event->eventParticipantId === 52
            && $event->queueTicketId === $queueTicketId
            && $event->nextServicePostId === $nextServicePostId;
    }

    private function assertSnapshotEvents(string $reason): void
    {
        $matchesSnapshot = static fn (object $event): bool => $event->eventId === 41
            && $event->reason === $reason
            && $event->eventParticipantId === 52
            && $event->queueTicketId === 53
            && $event->servicePostId === 61;

        EventFacade::assertDispatched(QueueUpdated::class, $matchesSnapshot);
        EventFacade::assertDispatched(DashboardUpdated::class, $matchesSnapshot);
        EventFacade::assertDispatched(TVMonitorUpdated::class, $matchesSnapshot);
    }
}
