<?php

namespace Tests\Feature;

use App\Enums\ParticipantServiceStatus;
use App\Enums\ParticipantServiceType;
use App\Enums\ParticipantStatus;
use App\Enums\QueueTicketStatus;
use App\Enums\RegistrationNumberFormat;
use App\Enums\ScreeningResult;
use App\Enums\ServicePostBehavior;
use App\Enums\UserRole;
use App\Events\ParticipantCheckedIn;
use App\Events\ServiceQueueUpdated;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Participant;
use App\Models\QueueTicket;
use App\Models\ServicePost;
use App\Models\User;
use App\Services\CheckIn\CheckInService;
use App\Services\Dashboard\DashboardService;
use App\Services\Monitor\MonitorService;
use App\Services\Report\ReportService;
use App\Services\Workflow\ServiceQueueService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
use Tests\TestCase;

class BusinessWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_requires_at_least_one_selected_service(): void
    {
        $actor = $this->administrator();
        [$event] = $this->workflow($actor);
        $participant = Participant::factory()->create();

        $this->actingAs($actor)
            ->post(route('events.check-ins.store', $event), [
                'participant_id' => $participant->id,
                'services' => [],
            ])
            ->assertSessionHasErrors('services');

        $this->assertDatabaseCount('event_participants', 0);
    }

    public function test_donor_only_registration_starts_at_screening_without_donor_number(): void
    {
        EventFacade::fake([ParticipantCheckedIn::class, ServiceQueueUpdated::class]);
        $actor = $this->administrator();
        [$event, $screeningPost, $donationPost] = $this->workflow($actor);

        $result = app(CheckInService::class)->checkIn(
            $event,
            Participant::factory()->create(),
            $actor,
            [ParticipantServiceType::Donor],
        );

        $this->assertSame('R001', $result->registrationNumber);
        $this->assertSame($screeningPost->id, $result->queueTicket->service_post_id);
        $this->assertSame(ParticipantStatus::WaitingScreening, $result->eventParticipant->status);
        $this->assertServiceStatus(
            $result->eventParticipant,
            ParticipantServiceType::Donor,
            ParticipantServiceStatus::WaitingScreening,
        );
        $this->assertDatabaseMissing('queue_tickets', [
            'event_participant_id' => $result->eventParticipant->id,
            'service_post_id' => $donationPost->id,
        ]);
        EventFacade::assertDispatched(
            ParticipantCheckedIn::class,
            fn (ParticipantCheckedIn $event): bool => $event->registrationNumber === 'R001'
                && $event->services === [ParticipantServiceType::Donor->value],
        );
    }

    public function test_health_check_only_never_enters_screening_or_donation_queue(): void
    {
        EventFacade::fake([ParticipantCheckedIn::class, ServiceQueueUpdated::class]);
        $actor = $this->administrator();
        [$event, $screeningPost, $donationPost, $healthPost] = $this->workflow($actor);

        $result = app(CheckInService::class)->checkIn(
            $event,
            Participant::factory()->create(),
            $actor,
            [ParticipantServiceType::HealthCheck],
        );

        $this->assertSame($healthPost->id, $result->queueTicket->service_post_id);
        $this->assertSame(ParticipantStatus::WaitingHealth, $result->eventParticipant->status);
        $this->assertServiceStatus(
            $result->eventParticipant,
            ParticipantServiceType::HealthCheck,
            ParticipantServiceStatus::WaitingHealthCheck,
        );
        $this->assertDatabaseMissing('queue_tickets', [
            'event_participant_id' => $result->eventParticipant->id,
            'service_post_id' => $screeningPost->id,
        ]);
        $this->assertDatabaseMissing('queue_tickets', [
            'event_participant_id' => $result->eventParticipant->id,
            'service_post_id' => $donationPost->id,
        ]);
    }

    public function test_eligible_donor_gets_donor_number_then_continues_to_selected_health_check(): void
    {
        EventFacade::fake([ParticipantCheckedIn::class, ServiceQueueUpdated::class]);
        $actor = $this->administrator();
        [$event, $screeningPost, $donationPost, $healthPost] = $this->workflow($actor);
        $result = app(CheckInService::class)->checkIn(
            $event,
            Participant::factory()->create(),
            $actor,
            [ParticipantServiceType::Donor, ParticipantServiceType::HealthCheck],
        );

        app(ServiceQueueService::class)->complete(
            $event,
            $screeningPost,
            $result->queueTicket,
            ['result' => ScreeningResult::Eligible->value],
            $actor,
        );

        $donorTicket = $this->waitingTicket($result->eventParticipant, $donationPost);
        $this->assertSame('D001', $donorTicket->formattedNumber());
        $this->assertServiceStatus(
            $result->eventParticipant,
            ParticipantServiceType::Donor,
            ParticipantServiceStatus::WaitingDonation,
        );
        $this->assertServiceStatus(
            $result->eventParticipant,
            ParticipantServiceType::HealthCheck,
            ParticipantServiceStatus::Pending,
        );

        app(ServiceQueueService::class)->complete(
            $event,
            $donationPost,
            $donorTicket,
            ['notes' => 'Donor selesai.'],
            $actor,
        );

        $healthTicket = $this->waitingTicket($result->eventParticipant, $healthPost);
        $this->assertSame('H001', $healthTicket->formattedNumber());
        $this->assertServiceStatus(
            $result->eventParticipant,
            ParticipantServiceType::Donor,
            ParticipantServiceStatus::Completed,
        );
        $this->assertServiceStatus(
            $result->eventParticipant,
            ParticipantServiceType::HealthCheck,
            ParticipantServiceStatus::WaitingHealthCheck,
        );

        app(ServiceQueueService::class)->complete(
            $event,
            $healthPost,
            $healthTicket,
            ['blood_pressure' => '120/80'],
            $actor,
        );

        $result->eventParticipant->refresh();
        $this->assertSame(ParticipantStatus::HealthCheckCompleted, $result->eventParticipant->status);
        $this->assertNull($result->eventParticipant->current_service_post_id);
        $this->assertServiceStatus(
            $result->eventParticipant,
            ParticipantServiceType::HealthCheck,
            ParticipantServiceStatus::Completed,
        );
        $this->assertDatabaseHas('health_assessments', [
            'event_participant_id' => $result->eventParticipant->id,
            'service_post_id' => $healthPost->id,
            'blood_pressure' => '120/80',
        ]);
        EventFacade::assertDispatchedTimes(ServiceQueueUpdated::class, 3);
    }

    public function test_not_eligible_donor_with_health_selection_skips_donation_and_goes_to_health(): void
    {
        EventFacade::fake([ParticipantCheckedIn::class, ServiceQueueUpdated::class]);
        $actor = $this->administrator();
        [$event, $screeningPost, $donationPost, $healthPost] = $this->workflow($actor);
        $result = app(CheckInService::class)->checkIn(
            $event,
            Participant::factory()->create(),
            $actor,
            [ParticipantServiceType::Donor, ParticipantServiceType::HealthCheck],
        );

        app(ServiceQueueService::class)->complete(
            $event,
            $screeningPost,
            $result->queueTicket,
            [
                'result' => ScreeningResult::NotEligible->value,
                'reason' => 'Hemoglobin rendah.',
            ],
            $actor,
        );

        $this->assertDatabaseMissing('queue_tickets', [
            'event_participant_id' => $result->eventParticipant->id,
            'service_post_id' => $donationPost->id,
        ]);
        $this->assertSame(
            $healthPost->id,
            $this->waitingTicket($result->eventParticipant, $healthPost)->service_post_id,
        );
        $this->assertServiceStatus(
            $result->eventParticipant,
            ParticipantServiceType::Donor,
            ParticipantServiceStatus::NotEligible,
        );
        $this->assertServiceStatus(
            $result->eventParticipant,
            ParticipantServiceType::HealthCheck,
            ParticipantServiceStatus::WaitingHealthCheck,
        );
    }

    public function test_not_eligible_donor_without_health_selection_finishes_without_donor_number(): void
    {
        EventFacade::fake([ParticipantCheckedIn::class, ServiceQueueUpdated::class]);
        $actor = $this->administrator();
        [$event, $screeningPost, $donationPost] = $this->workflow($actor);
        $result = app(CheckInService::class)->checkIn(
            $event,
            Participant::factory()->create(),
            $actor,
            [ParticipantServiceType::Donor],
        );

        app(ServiceQueueService::class)->complete(
            $event,
            $screeningPost,
            $result->queueTicket,
            [
                'result' => ScreeningResult::NotEligible->value,
                'reason' => 'Belum memenuhi syarat.',
            ],
            $actor,
        );

        $result->eventParticipant->refresh();
        $this->assertSame(ParticipantStatus::NotEligible, $result->eventParticipant->status);
        $this->assertNull($result->eventParticipant->current_service_post_id);
        $this->assertDatabaseMissing('queue_tickets', [
            'event_participant_id' => $result->eventParticipant->id,
            'service_post_id' => $donationPost->id,
        ]);
    }

    public function test_dashboard_metrics_are_derived_from_selected_services_and_outcomes(): void
    {
        EventFacade::fake([ParticipantCheckedIn::class, ServiceQueueUpdated::class]);
        $actor = $this->administrator();
        [$event, $screeningPost, $donationPost, $healthPost] = $this->workflow($actor);

        $eligible = app(CheckInService::class)->checkIn(
            $event,
            Participant::factory()->create(),
            $actor,
            [ParticipantServiceType::Donor],
        );
        app(ServiceQueueService::class)->complete(
            $event,
            $screeningPost,
            $eligible->queueTicket,
            ['result' => ScreeningResult::Eligible->value],
            $actor,
        );
        app(ServiceQueueService::class)->start(
            $event,
            $donationPost,
            $this->waitingTicket($eligible->eventParticipant, $donationPost),
            $actor,
        );

        $healthOnly = app(CheckInService::class)->checkIn(
            $event,
            Participant::factory()->create(),
            $actor,
            [ParticipantServiceType::HealthCheck],
        );
        app(ServiceQueueService::class)->complete(
            $event,
            $healthPost,
            $healthOnly->queueTicket,
            ['blood_pressure' => '118/78'],
            $actor,
        );

        $bothNotEligible = app(CheckInService::class)->checkIn(
            $event,
            Participant::factory()->create(),
            $actor,
            [ParticipantServiceType::Donor, ParticipantServiceType::HealthCheck],
        );
        app(ServiceQueueService::class)->complete(
            $event,
            $screeningPost,
            $bothNotEligible->queueTicket,
            [
                'result' => ScreeningResult::NotEligible->value,
                'reason' => 'Tekanan darah belum memenuhi syarat.',
            ],
            $actor,
        );

        $metrics = app(DashboardService::class)->snapshot()['metrics'];

        $this->assertSame(3, $metrics['checked_in']);
        $this->assertSame(2, $metrics['selected_donor']);
        $this->assertSame(2, $metrics['selected_health_check']);
        $this->assertSame(1, $metrics['selected_both']);
        $this->assertSame(1, $metrics['eligible_donor']);
        $this->assertSame(1, $metrics['not_eligible_donor']);
        $this->assertSame(1, $metrics['donation_in_progress']);
        $this->assertSame(1, $metrics['health_check_completed']);
    }

    public function test_tv_monitor_only_shows_participants_when_their_selected_service_is_ready(): void
    {
        EventFacade::fake([ParticipantCheckedIn::class, ServiceQueueUpdated::class]);
        $actor = $this->administrator();
        [$event, $screeningPost, $donationPost, $healthPost] = $this->workflow($actor);
        $donorAndHealth = app(CheckInService::class)->checkIn(
            $event,
            Participant::factory()->create(['name' => 'Donor Keduanya']),
            $actor,
            [ParticipantServiceType::Donor, ParticipantServiceType::HealthCheck],
        );
        app(CheckInService::class)->checkIn(
            $event,
            Participant::factory()->create(['name' => 'Kesehatan Saja']),
            $actor,
            [ParticipantServiceType::HealthCheck],
        );

        $initialQueues = collect(app(MonitorService::class)->snapshot()['queues'])->keyBy('behavior');

        $this->assertSame(1, $initialQueues[ServicePostBehavior::ScreeningForm->value]['waiting_count']);
        $this->assertSame(0, $initialQueues[ServicePostBehavior::DonationForm->value]['waiting_count']);
        $this->assertSame(1, $initialQueues[ServicePostBehavior::HealthForm->value]['waiting_count']);
        $this->assertSame(
            'Kesehatan Saja',
            $initialQueues[ServicePostBehavior::HealthForm->value]['waiting'][0]['participant_name'],
        );

        app(ServiceQueueService::class)->complete(
            $event,
            $screeningPost,
            $donorAndHealth->queueTicket,
            ['result' => ScreeningResult::Eligible->value],
            $actor,
        );
        $donationQueues = collect(app(MonitorService::class)->snapshot()['queues'])->keyBy('behavior');
        $this->assertSame(1, $donationQueues[ServicePostBehavior::DonationForm->value]['waiting_count']);
        $this->assertSame(1, $donationQueues[ServicePostBehavior::HealthForm->value]['waiting_count']);

        app(ServiceQueueService::class)->complete(
            $event,
            $donationPost,
            $this->waitingTicket($donorAndHealth->eventParticipant, $donationPost),
            [],
            $actor,
        );
        $healthQueues = collect(app(MonitorService::class)->snapshot()['queues'])->keyBy('behavior');
        $this->assertSame(2, $healthQueues[ServicePostBehavior::HealthForm->value]['waiting_count']);
        $this->assertSame(
            $healthPost->id,
            $this->waitingTicket($donorAndHealth->eventParticipant, $healthPost)->service_post_id,
        );
    }

    public function test_report_contains_business_service_metrics_and_registration_identity(): void
    {
        EventFacade::fake([ParticipantCheckedIn::class, ServiceQueueUpdated::class]);
        $actor = $this->administrator();
        [$event, $screeningPost, $donationPost, $healthPost] = $this->workflow($actor);
        $completed = app(CheckInService::class)->checkIn(
            $event,
            Participant::factory()->create(['name' => 'Peserta Lengkap']),
            $actor,
            [ParticipantServiceType::Donor, ParticipantServiceType::HealthCheck],
        );
        app(ServiceQueueService::class)->complete(
            $event,
            $screeningPost,
            $completed->queueTicket,
            ['result' => ScreeningResult::Eligible->value],
            $actor,
        );
        app(ServiceQueueService::class)->complete(
            $event,
            $donationPost,
            $this->waitingTicket($completed->eventParticipant, $donationPost),
            [],
            $actor,
        );
        app(ServiceQueueService::class)->complete(
            $event,
            $healthPost,
            $this->waitingTicket($completed->eventParticipant, $healthPost),
            ['notes' => 'Pemeriksaan selesai.'],
            $actor,
        );

        $notEligible = app(CheckInService::class)->checkIn(
            $event,
            Participant::factory()->create(['name' => 'Peserta Tidak Layak']),
            $actor,
            [ParticipantServiceType::Donor],
        );
        app(ServiceQueueService::class)->complete(
            $event,
            $screeningPost,
            $notEligible->queueTicket,
            [
                'result' => ScreeningResult::NotEligible->value,
                'reason' => 'Belum memenuhi syarat.',
            ],
            $actor,
        );

        $snapshot = app(ReportService::class)->snapshot($event);
        $record = collect($snapshot->records)->firstWhere('name', 'Peserta Lengkap');

        $this->assertSame(2, $snapshot->metrics['checked_in']);
        $this->assertSame(2, $snapshot->metrics['selected_donor']);
        $this->assertSame(1, $snapshot->metrics['selected_health_check']);
        $this->assertSame(1, $snapshot->metrics['selected_both']);
        $this->assertSame(1, $snapshot->metrics['eligible_donor']);
        $this->assertSame(1, $snapshot->metrics['not_eligible_donor']);
        $this->assertSame(1, $snapshot->metrics['donor_completed']);
        $this->assertSame(1, $snapshot->metrics['health_check_completed']);
        $this->assertSame('R001', $record['registration_number']);
        $this->assertSame('Donor Darah + Cek Kesehatan', $record['selected_services_text']);
        $this->assertSame('D001', $record['donor_queue_number']);
    }

    /**
     * @return array{Event, ServicePost, ServicePost, ServicePost}
     */
    private function workflow(User $actor): array
    {
        $event = Event::factory()->active()->create(['created_by' => $actor->id]);
        $event->settings()->update([
            'registration_number_format' => RegistrationNumberFormat::Uniform,
        ]);
        $event->unsetRelation('settings');
        $screening = $this->servicePost($event, 'kelayakan', 1, ServicePostBehavior::ScreeningForm, 'K');
        $donation = $this->servicePost($event, 'donor', 2, ServicePostBehavior::DonationForm, 'D');
        $health = $this->servicePost($event, 'kesehatan', 3, ServicePostBehavior::HealthForm, 'H');

        return [$event, $screening, $donation, $health];
    }

    private function servicePost(
        Event $event,
        string $code,
        int $sequence,
        ServicePostBehavior $behavior,
        string $prefix,
    ): ServicePost {
        return ServicePost::factory()->for($event)->create([
            'code' => $code,
            'name' => str($code)->title()->toString(),
            'behavior' => $behavior,
            'queue_prefix' => $prefix,
            'queue_number_digits' => 3,
            'sequence' => $sequence,
            'is_active' => true,
        ]);
    }

    private function waitingTicket(EventParticipant $participant, ServicePost $post): QueueTicket
    {
        return QueueTicket::query()
            ->where('event_participant_id', $participant->id)
            ->where('service_post_id', $post->id)
            ->where('status', QueueTicketStatus::Waiting->value)
            ->with(['event.settings', 'servicePost'])
            ->firstOrFail();
    }

    private function assertServiceStatus(
        EventParticipant $participant,
        ParticipantServiceType $service,
        ParticipantServiceStatus $status,
    ): void {
        $this->assertDatabaseHas('event_participant_services', [
            'event_participant_id' => $participant->id,
            'service' => $service->value,
            'status' => $status->value,
        ]);
    }

    private function administrator(): User
    {
        $this->seed(RolePermissionSeeder::class);

        return User::query()->role(UserRole::Administrator->value)->firstOrFail();
    }
}
