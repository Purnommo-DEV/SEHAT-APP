<?php

namespace Tests\Feature;

use App\Enums\ParticipantGender;
use App\Enums\ParticipantServiceStatus;
use App\Enums\ParticipantServiceType;
use App\Enums\ParticipantStatus;
use App\Enums\QueueTicketStatus;
use App\Enums\ScreeningResult;
use App\Enums\ServicePostBehavior;
use App\Models\DonorScreening;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventParticipantService;
use App\Models\HealthAssessment;
use App\Models\Participant;
use App\Models\QueueTicket;
use App\Models\ServicePost;
use App\Models\User;
use App\Services\CheckIn\CheckInService;
use App\Services\Dashboard\DashboardService;
use App\Services\Report\ReportService;
use App\Services\Workflow\ServiceQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinalSopHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_cannot_be_cancelled_after_a_service_has_completed(): void
    {
        [$event, $screening] = $this->workflow();
        $actor = User::factory()->create();
        $registration = $this->checkIn(
            $event,
            $actor,
            [ParticipantServiceType::Donor, ParticipantServiceType::HealthCheck],
        );
        $screeningTicket = $this->waitingTicket($registration, $screening);

        app(ServiceQueueService::class)->complete(
            $event,
            $screening,
            $screeningTicket,
            ['result' => ScreeningResult::Eligible->value],
            $actor,
        );

        try {
            app(CheckInService::class)->cancel($event, $registration, $actor);
            $this->fail('Cancellation should be rejected after eligibility has completed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('registration', $exception->errors());
        }

        $registration->refresh();
        $this->assertSame(ParticipantStatus::WaitingDonor, $registration->status);
        $this->assertNotNull($registration->active_registration_number);
        $this->assertSame(QueueTicketStatus::Finished, $screeningTicket->refresh()->status);
        $this->assertSame(
            ParticipantServiceStatus::WaitingDonation,
            $this->service($registration, ParticipantServiceType::Donor)->status,
        );
    }

    public function test_ineligible_legacy_health_first_participant_finishes_without_a_second_health_queue(): void
    {
        [$event, $screening, , $health] = $this->workflow();
        $actor = User::factory()->create();
        $registration = $this->checkIn(
            $event,
            $actor,
            [ParticipantServiceType::Donor, ParticipantServiceType::HealthCheck],
        );
        $this->markHealthAsHistoricallyCompleted($registration);
        $screeningTicket = $this->waitingTicket($registration, $screening);

        app(ServiceQueueService::class)->complete(
            $event,
            $screening,
            $screeningTicket,
            ['result' => ScreeningResult::NotEligible->value, 'reason' => 'HB rendah'],
            $actor,
        );

        $registration->refresh();
        $this->assertSame(ParticipantStatus::HealthCheckCompleted, $registration->status);
        $this->assertNull($registration->current_service_post_id);
        $this->assertNotNull($registration->completed_at);
        $this->assertSame(
            ParticipantServiceStatus::NotEligible,
            $this->service($registration, ParticipantServiceType::Donor)->status,
        );
        $this->assertDatabaseMissing('queue_tickets', [
            'event_participant_id' => $registration->id,
            'service_post_id' => $health->id,
        ]);
    }

    public function test_donation_completion_does_not_requeue_a_legacy_completed_health_service(): void
    {
        [$event, $screening, $donation, $health] = $this->workflow();
        $actor = User::factory()->create();
        $registration = $this->checkIn(
            $event,
            $actor,
            [ParticipantServiceType::Donor, ParticipantServiceType::HealthCheck],
        );
        $this->markHealthAsHistoricallyCompleted($registration);

        app(ServiceQueueService::class)->complete(
            $event,
            $screening,
            $this->waitingTicket($registration, $screening),
            ['result' => ScreeningResult::Eligible->value],
            $actor,
        );
        app(ServiceQueueService::class)->complete(
            $event,
            $donation,
            $this->waitingTicket($registration, $donation),
            [],
            $actor,
        );

        $registration->refresh();
        $this->assertSame(ParticipantStatus::HealthCheckCompleted, $registration->status);
        $this->assertNull($registration->current_service_post_id);
        $this->assertSame(
            ParticipantServiceStatus::Completed,
            $this->service($registration, ParticipantServiceType::Donor)->status,
        );
        $this->assertDatabaseMissing('queue_tickets', [
            'event_participant_id' => $registration->id,
            'service_post_id' => $health->id,
        ]);
    }

    public function test_eligible_dashboard_metric_is_immutable_when_donor_queue_is_cancelled(): void
    {
        [$event, $screening, $donation] = $this->workflow();
        $actor = User::factory()->create();
        $registration = $this->checkIn($event, $actor, [ParticipantServiceType::Donor]);

        app(ServiceQueueService::class)->complete(
            $event,
            $screening,
            $this->waitingTicket($registration, $screening),
            ['result' => ScreeningResult::Eligible->value],
            $actor,
        );

        $donorTicket = $this->waitingTicket($registration, $donation);
        app(ServiceQueueService::class)->cancel(
            $event,
            $donation,
            $donorTicket,
            $actor,
        );

        $dashboard = app(DashboardService::class)->snapshot();
        $report = app(ReportService::class)->snapshot($event);

        $this->assertSame(1, $dashboard['metrics']['eligible_donor']);
        $this->assertSame(1, $report->metrics['eligible_donor']);
        $this->assertSame(0, $dashboard['metrics']['donor']);
    }

    public function test_legacy_health_timestamps_are_reconciled_from_operational_records(): void
    {
        [$event, , , $health] = $this->workflow();
        $actor = User::factory()->create();
        $registration = $this->checkIn($event, $actor, [ParticipantServiceType::HealthCheck]);
        $ticket = $this->waitingTicket($registration, $health);
        $startedAt = now()->subMinutes(20)->startOfSecond();
        $completedAt = now()->subMinutes(10)->startOfSecond();
        $ticket->status = QueueTicketStatus::Finished;
        $ticket->served_at = $startedAt;
        $ticket->finished_at = $completedAt;
        $ticket->save();
        $healthService = $this->service($registration, ParticipantServiceType::HealthCheck);
        $healthService->status = ParticipantServiceStatus::Completed;
        $healthService->started_at = null;
        $healthService->completed_at = now()->subDay();
        $healthService->save();
        $registration->completed_at = now()->subDay();
        $registration->save();
        HealthAssessment::factory()->create([
            'event_id' => $event->id,
            'event_participant_id' => $registration->id,
            'service_post_id' => $health->id,
            'created_by' => $actor->id,
            'created_at' => $completedAt,
        ]);

        $migration = require database_path(
            'migrations/2026_07_30_000026_reconcile_legacy_health_timestamps.php',
        );
        $migration->up();

        $healthService->refresh();
        $registration->refresh();
        $this->assertSame($startedAt->toDateTimeString(), $healthService->started_at?->toDateTimeString());
        $this->assertSame($completedAt->toDateTimeString(), $healthService->completed_at?->toDateTimeString());
        $this->assertSame($completedAt->toDateTimeString(), $registration->completed_at?->toDateTimeString());
    }

    public function test_legacy_eligibility_without_a_queue_ticket_uses_the_screening_timestamp(): void
    {
        [$event, $screening] = $this->workflow();
        $actor = User::factory()->create();
        $registration = $this->checkIn($event, $actor, [ParticipantServiceType::Donor]);
        QueueTicket::query()
            ->where('event_participant_id', $registration->id)
            ->delete();
        $decisionAt = now()->subMinutes(15)->startOfSecond();
        DonorScreening::factory()->create([
            'event_id' => $event->id,
            'event_participant_id' => $registration->id,
            'service_post_id' => $screening->id,
            'result' => ScreeningResult::NotEligible,
            'screened_by' => $actor->id,
            'created_at' => $decisionAt,
            'updated_at' => $decisionAt,
        ]);
        $donorService = $this->service($registration, ParticipantServiceType::Donor);
        $donorService->status = ParticipantServiceStatus::NotEligible;
        $donorService->eligibility_started_at = null;
        $donorService->eligibility_completed_at = null;
        $donorService->completed_at = $decisionAt;
        $donorService->save();

        $migration = require database_path(
            'migrations/2026_07_30_000027_reconcile_legacy_donor_eligibility_timestamps.php',
        );
        $migration->up();

        $donorService->refresh();
        $this->assertSame(
            $decisionAt->toDateTimeString(),
            $donorService->eligibility_started_at?->toDateTimeString(),
        );
        $this->assertSame(
            $decisionAt->toDateTimeString(),
            $donorService->eligibility_completed_at?->toDateTimeString(),
        );
    }

    /**
     * @return array{Event, ServicePost, ServicePost, ServicePost}
     */
    private function workflow(): array
    {
        $event = Event::factory()->active()->create();
        $screening = $this->servicePost($event, 'eligibility', 1, ServicePostBehavior::ScreeningForm);
        $donation = $this->servicePost($event, 'donation', 2, ServicePostBehavior::DonationForm);
        $health = $this->servicePost($event, 'health', 3, ServicePostBehavior::HealthForm);

        return [$event, $screening, $donation, $health];
    }

    private function servicePost(
        Event $event,
        string $code,
        int $sequence,
        ServicePostBehavior $behavior,
    ): ServicePost {
        return ServicePost::factory()->for($event)->create([
            'code' => $code,
            'name' => str($code)->title()->toString(),
            'behavior' => $behavior,
            'sequence' => $sequence,
            'is_active' => true,
        ]);
    }

    /**
     * @param  list<ParticipantServiceType>  $services
     */
    private function checkIn(Event $event, User $actor, array $services): EventParticipant
    {
        return app(CheckInService::class)->checkIn(
            $event,
            Participant::factory()->create(['gender' => ParticipantGender::Male]),
            $actor,
            $services,
        )->eventParticipant;
    }

    private function waitingTicket(
        EventParticipant $participant,
        ServicePost $post,
    ): QueueTicket {
        return QueueTicket::query()
            ->where('event_participant_id', $participant->id)
            ->where('service_post_id', $post->id)
            ->where('status', QueueTicketStatus::Waiting->value)
            ->firstOrFail();
    }

    private function service(
        EventParticipant $participant,
        ParticipantServiceType $type,
    ): EventParticipantService {
        return EventParticipantService::query()
            ->where('event_participant_id', $participant->id)
            ->where('service', $type->value)
            ->firstOrFail();
    }

    private function markHealthAsHistoricallyCompleted(EventParticipant $participant): void
    {
        $health = $this->service($participant, ParticipantServiceType::HealthCheck);
        $health->status = ParticipantServiceStatus::Completed;
        $health->started_at = now()->subMinutes(10);
        $health->completed_at = now()->subMinutes(5);
        $health->save();
    }
}
