<?php

namespace Tests\Feature;

use App\Enums\ParticipantServiceStatus;
use App\Enums\ParticipantServiceType;
use App\Enums\ParticipantStatus;
use App\Enums\QueueTicketStatus;
use App\Enums\QueueType;
use App\Enums\ScreeningResult;
use App\Enums\ServicePostBehavior;
use App\Enums\ServicePostType;
use App\Events\DashboardUpdated;
use App\Events\DonorQueueUpdated;
use App\Events\HealthQueueUpdated;
use App\Events\ParticipantDonationCompleted;
use App\Events\ParticipantEligible;
use App\Events\ParticipantHealthCheckCompleted;
use App\Events\ParticipantMovedToDonation;
use App\Events\ParticipantMovedToHealthCheck;
use App\Events\QueueUpdated;
use App\Events\ScreeningUpdated;
use App\Events\TVMonitorUpdated;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Participant;
use App\Models\QueueTicket;
use App\Models\ServicePost;
use App\Models\User;
use App\Services\Donation\DonorQueueService;
use App\Services\Health\HealthQueueService;
use App\Services\Screening\DonorScreeningService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
use Tests\TestCase;

class LegacyWorkflowCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_compatibility_service_uses_behavior_and_sequence(): void
    {
        EventFacade::fake();
        $actor = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $actor->id]);
        $healthPost = $this->servicePost($event, 'pemeriksaan-a', 1, ServicePostBehavior::HealthForm);
        $nextPost = $this->servicePost($event, 'verifikasi', 2, ServicePostBehavior::CustomForm);
        $ticket = $this->ticket($event, $healthPost, ParticipantStatus::HealthInProgress, QueueTicketStatus::Serving);

        app(HealthQueueService::class)->complete($event, $ticket, [
            'blood_pressure' => '120/80',
        ], $actor);

        $participant = $ticket->eventParticipant->refresh();
        $this->assertSame($nextPost->id, $participant->current_service_post_id);
        $this->assertSame(ParticipantStatus::WaitingScreening, $participant->status);
        $this->assertDatabaseHas('health_assessments', [
            'event_participant_id' => $participant->id,
            'service_post_id' => $healthPost->id,
        ]);
        EventFacade::assertDispatched(ParticipantHealthCheckCompleted::class);
        EventFacade::assertDispatched(QueueUpdated::class);
        EventFacade::assertDispatched(DashboardUpdated::class);
        EventFacade::assertDispatched(TVMonitorUpdated::class);
        EventFacade::assertDispatched(HealthQueueUpdated::class);
    }

    public function test_screening_compatibility_service_uses_the_next_active_post_regardless_of_type(): void
    {
        EventFacade::fake();
        $actor = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $actor->id]);
        $screeningPost = $this->servicePost($event, 'screening-a', 1, ServicePostBehavior::ScreeningForm);
        $nextPost = $this->servicePost($event, 'observasi', 2, ServicePostBehavior::CustomForm);
        $participant = EventParticipant::factory()
            ->for($event)
            ->for(Participant::factory())
            ->create([
                'current_service_post_id' => $screeningPost->id,
                'status' => ParticipantStatus::WaitingScreening,
                'checked_in_at' => now(),
            ]);

        $decision = app(DonorScreeningService::class)->decide($event, $participant, [
            'result' => ScreeningResult::Eligible->value,
        ], $actor);

        $participant->refresh();
        $this->assertFalse($decision->alreadyDecided);
        $this->assertSame($nextPost->id, $participant->current_service_post_id);
        $this->assertSame(ParticipantStatus::WaitingDonor, $participant->status);
        $this->assertSame(
            QueueTicket::donorQueueTypeFor($participant->participant->gender),
            $decision->donorQueueTicket?->queue_type,
        );
        $this->assertSame($nextPost->id, $decision->donorQueueTicket->service_post_id);
        EventFacade::assertDispatched(ParticipantEligible::class);
        EventFacade::assertDispatched(ParticipantMovedToDonation::class);
        EventFacade::assertDispatched(QueueUpdated::class);
        EventFacade::assertDispatched(DashboardUpdated::class);
        EventFacade::assertDispatched(TVMonitorUpdated::class);
        EventFacade::assertDispatched(ScreeningUpdated::class);
    }

    public function test_donation_compatibility_service_uses_behavior_and_sequence(): void
    {
        EventFacade::fake();
        $actor = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $actor->id]);
        $donationPost = $this->servicePost($event, 'bed-a', 1, ServicePostBehavior::DonationForm);
        $nextPost = $this->servicePost($event, 'observasi', 2, ServicePostBehavior::ConfirmationOnly);
        $ticket = $this->ticket($event, $donationPost, ParticipantStatus::WaitingDonor, QueueTicketStatus::Calling);

        $service = app(DonorQueueService::class);
        $service->start($event, $ticket, $actor);
        $service->complete($event, $ticket, $actor);

        $participant = $ticket->eventParticipant->refresh();
        $this->assertSame(QueueTicketStatus::Finished, $ticket->refresh()->status);
        $this->assertSame(ParticipantStatus::DonationCompleted, $participant->status);
        $this->assertSame($nextPost->id, $participant->current_service_post_id);
        EventFacade::assertDispatched(ParticipantDonationCompleted::class);
        EventFacade::assertDispatched(QueueUpdated::class);
        EventFacade::assertDispatched(DashboardUpdated::class);
        EventFacade::assertDispatched(TVMonitorUpdated::class);
        EventFacade::assertDispatched(DonorQueueUpdated::class);
    }

    public function test_legacy_screening_route_delegates_sop_participant_to_canonical_workflow(): void
    {
        $actor = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $actor->id]);
        $screeningPost = $this->servicePost($event, 'eligibility', 1, ServicePostBehavior::ScreeningForm);
        $donationPost = $this->servicePost($event, 'donation', 2, ServicePostBehavior::DonationForm);
        $participant = EventParticipant::factory()
            ->for($event)
            ->for(Participant::factory())
            ->create([
                'current_service_post_id' => $screeningPost->id,
                'status' => ParticipantStatus::WaitingScreening,
                'checked_in_at' => now(),
            ]);
        $selectedDonor = $participant->services()->create([
            'event_id' => $event->id,
            'service' => ParticipantServiceType::Donor,
            'status' => ParticipantServiceStatus::WaitingScreening,
            'selected_at' => now(),
        ]);
        $sourceTicket = $this->ticket(
            $event,
            $screeningPost,
            ParticipantStatus::WaitingScreening,
            QueueTicketStatus::Waiting,
            $participant,
        );
        EventFacade::fake();

        $this->actingAs($actor)
            ->post(route('events.screening.store', [$event, $participant]), [
                'result' => ScreeningResult::Eligible->value,
            ])
            ->assertRedirect(route('events.screening.index', $event));

        $this->assertSame(QueueTicketStatus::Finished, $sourceTicket->refresh()->status);
        $this->assertSame(ParticipantServiceStatus::WaitingDonation, $selectedDonor->refresh()->status);
        $this->assertNotNull($selectedDonor->eligibility_started_at);
        $this->assertNotNull($selectedDonor->eligibility_completed_at);
        $this->assertSame(ParticipantStatus::WaitingDonor, $participant->refresh()->status);
        $this->assertSame($donationPost->id, $participant->current_service_post_id);
        $targetTicket = QueueTicket::query()
            ->where('event_participant_id', $participant->id)
            ->where('service_post_id', $donationPost->id)
            ->firstOrFail();

        EventFacade::assertDispatched(ParticipantEligible::class);
        EventFacade::assertDispatched(
            ParticipantMovedToDonation::class,
            fn (ParticipantMovedToDonation $broadcast): bool => $broadcast->queueTicketId === $targetTicket->id,
        );
        $this->assertCanonicalSnapshotFanout();
        EventFacade::assertDispatched(ScreeningUpdated::class);
    }

    public function test_legacy_donation_routes_delegate_sop_participant_and_preserve_service_timestamps(): void
    {
        $actor = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $actor->id]);
        $donationPost = $this->servicePost($event, 'donation', 1, ServicePostBehavior::DonationForm);
        $healthPost = $this->servicePost($event, 'health', 2, ServicePostBehavior::HealthForm);
        $participant = EventParticipant::factory()
            ->for($event)
            ->for(Participant::factory())
            ->create([
                'current_service_post_id' => $donationPost->id,
                'status' => ParticipantStatus::WaitingDonor,
                'checked_in_at' => now(),
            ]);
        $selectedDonor = $participant->services()->create([
            'event_id' => $event->id,
            'service' => ParticipantServiceType::Donor,
            'status' => ParticipantServiceStatus::WaitingDonation,
            'selected_at' => now(),
            'eligibility_started_at' => now(),
            'eligibility_completed_at' => now(),
        ]);
        $selectedHealth = $participant->services()->create([
            'event_id' => $event->id,
            'service' => ParticipantServiceType::HealthCheck,
            'status' => ParticipantServiceStatus::Pending,
            'selected_at' => now(),
        ]);
        $ticket = $this->ticket(
            $event,
            $donationPost,
            ParticipantStatus::WaitingDonor,
            QueueTicketStatus::Calling,
            $participant,
        );
        EventFacade::fake();

        $this->actingAs($actor)
            ->post(route('events.donation.start', [$event, $ticket]))
            ->assertRedirect();
        $this->assertSame(ParticipantServiceStatus::DonationInProgress, $selectedDonor->refresh()->status);
        $this->assertNotNull($selectedDonor->started_at);

        $this->actingAs($actor)
            ->post(route('events.donation.complete', [$event, $ticket]))
            ->assertRedirect();

        $this->assertSame(ParticipantServiceStatus::Completed, $selectedDonor->refresh()->status);
        $this->assertNotNull($selectedDonor->completed_at);
        $this->assertSame(ParticipantServiceStatus::WaitingHealthCheck, $selectedHealth->refresh()->status);
        $this->assertSame(ParticipantStatus::WaitingHealth, $participant->refresh()->status);
        $this->assertSame($healthPost->id, $participant->current_service_post_id);
        $targetTicket = QueueTicket::query()
            ->where('event_participant_id', $participant->id)
            ->where('service_post_id', $healthPost->id)
            ->firstOrFail();

        EventFacade::assertDispatched(ParticipantDonationCompleted::class);
        EventFacade::assertDispatched(
            ParticipantMovedToHealthCheck::class,
            fn (ParticipantMovedToHealthCheck $broadcast): bool => $broadcast->queueTicketId === $targetTicket->id,
        );
        $this->assertCanonicalSnapshotFanout();
        EventFacade::assertDispatchedTimes(DonorQueueUpdated::class, 2);
    }

    public function test_legacy_health_route_delegates_sop_participant_without_returning_to_screening(): void
    {
        $actor = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $actor->id]);
        $healthPost = $this->servicePost($event, 'health', 1, ServicePostBehavior::HealthForm);
        $screeningPost = $this->servicePost($event, 'old-screening', 2, ServicePostBehavior::ScreeningForm);
        $participant = EventParticipant::factory()
            ->for($event)
            ->for(Participant::factory())
            ->create([
                'current_service_post_id' => $healthPost->id,
                'status' => ParticipantStatus::HealthInProgress,
                'checked_in_at' => now(),
            ]);
        $selectedHealth = $participant->services()->create([
            'event_id' => $event->id,
            'service' => ParticipantServiceType::HealthCheck,
            'status' => ParticipantServiceStatus::HealthCheckInProgress,
            'selected_at' => now(),
            'started_at' => now(),
        ]);
        $ticket = $this->ticket(
            $event,
            $healthPost,
            ParticipantStatus::HealthInProgress,
            QueueTicketStatus::Serving,
            $participant,
        );
        EventFacade::fake();

        $this->actingAs($actor)
            ->post(route('events.health.assessments.store', [$event, $ticket]), [
                'blood_pressure' => '120/80',
            ])
            ->assertRedirect(route('events.health.index', $event));

        $this->assertSame(QueueTicketStatus::Finished, $ticket->refresh()->status);
        $this->assertSame(ParticipantServiceStatus::Completed, $selectedHealth->refresh()->status);
        $this->assertNotNull($selectedHealth->completed_at);
        $this->assertSame(ParticipantStatus::HealthCheckCompleted, $participant->refresh()->status);
        $this->assertNull($participant->current_service_post_id);
        $this->assertNotNull($participant->completed_at);
        $this->assertDatabaseMissing('queue_tickets', [
            'event_participant_id' => $participant->id,
            'service_post_id' => $screeningPost->id,
        ]);

        EventFacade::assertDispatched(ParticipantHealthCheckCompleted::class);
        $this->assertCanonicalSnapshotFanout();
        EventFacade::assertDispatched(HealthQueueUpdated::class);
    }

    private function servicePost(Event $event, string $code, int $sequence, ServicePostBehavior $behavior): ServicePost
    {
        return ServicePost::factory()->for($event)->create([
            'code' => $code,
            'type' => ServicePostType::Custom,
            'behavior' => $behavior,
            'sequence' => $sequence,
            'is_active' => true,
        ]);
    }

    private function ticket(
        Event $event,
        ServicePost $servicePost,
        ParticipantStatus $participantStatus,
        QueueTicketStatus $ticketStatus,
        ?EventParticipant $participant = null,
    ): QueueTicket {
        $participant ??= EventParticipant::factory()
            ->for($event)
            ->for(Participant::factory())
            ->create([
                'current_service_post_id' => $servicePost->id,
                'status' => $participantStatus,
                'checked_in_at' => now(),
            ]);

        return QueueTicket::factory()->create([
            'event_id' => $event->id,
            'event_participant_id' => $participant->id,
            'service_post_id' => $servicePost->id,
            'queue_type' => QueueType::General,
            'status' => $ticketStatus,
            'called_at' => now(),
            'served_at' => $ticketStatus === QueueTicketStatus::Serving ? now() : null,
        ]);
    }

    private function assertCanonicalSnapshotFanout(): void
    {
        EventFacade::assertDispatched(QueueUpdated::class);
        EventFacade::assertDispatched(DashboardUpdated::class);
        EventFacade::assertDispatched(TVMonitorUpdated::class);
    }

    private function administrator(): User
    {
        $this->seed(RolePermissionSeeder::class);

        return User::query()->role('Administrator')->firstOrFail();
    }
}
