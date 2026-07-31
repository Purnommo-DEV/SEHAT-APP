<?php

namespace Tests\Feature;

use App\Data\CheckInResult;
use App\Enums\DonorNumberMode;
use App\Enums\ParticipantGender;
use App\Enums\ParticipantServiceType;
use App\Enums\QueueTicketStatus;
use App\Enums\QueueType;
use App\Enums\RegistrationNumberFormat;
use App\Enums\ScreeningResult;
use App\Enums\ServicePostBehavior;
use App\Events\DashboardUpdated;
use App\Events\ParticipantEligible;
use App\Events\ParticipantMovedToDonation;
use App\Events\QueueUpdated;
use App\Events\TVMonitorUpdated;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventParticipantService;
use App\Models\Participant;
use App\Models\QueueTicket;
use App\Models\ServicePost;
use App\Models\User;
use App\Services\CheckIn\CheckInService;
use App\Services\Dashboard\DashboardService;
use App\Services\Monitor\MonitorService;
use App\Services\Queue\QueueNumberGenerator;
use App\Services\Workflow\ServiceQueueService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event as EventFacade;
use Tests\TestCase;

class FinalSopNumberingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_registration_uses_one_global_numeric_sequence_across_genders(): void
    {
        $actor = User::factory()->create();
        [$event] = $this->workflow($actor, DonorNumberMode::Global);
        $participants = [
            Participant::factory()->create(['gender' => ParticipantGender::Male]),
            Participant::factory()->create(['gender' => ParticipantGender::Female]),
            Participant::factory()->create(['gender' => ParticipantGender::Male]),
        ];

        $registrations = collect($participants)
            ->map(fn (Participant $participant) => app(CheckInService::class)->checkIn(
                $event,
                $participant,
                $actor,
                [ParticipantServiceType::HealthCheck],
            ));

        $this->assertSame(
            ['L001', 'P002', 'L003'],
            $registrations->pluck('registrationNumber')->all(),
        );
        $this->assertSame(
            [1, 2, 3],
            $registrations
                ->map(fn (CheckInResult $registration): int => $registration->eventParticipant->registration_number)
                ->all(),
        );
    }

    public function test_cancelled_registration_releases_the_smallest_registration_and_queue_gap(): void
    {
        $actor = User::factory()->create();
        [$event] = $this->workflow($actor, DonorNumberMode::Global);
        $checkInService = app(CheckInService::class);
        $registrations = collect([
            Participant::factory()->create(['gender' => ParticipantGender::Male]),
            Participant::factory()->create(['gender' => ParticipantGender::Female]),
            Participant::factory()->create(['gender' => ParticipantGender::Male]),
        ])->map(fn (Participant $participant) => $checkInService->checkIn(
            $event,
            $participant,
            $actor,
            [ParticipantServiceType::HealthCheck],
        ));
        $cancelledRegistration = $registrations->get(1);

        $checkInService->cancel(
            $event,
            $cancelledRegistration->eventParticipant,
            $actor,
        );

        $replacement = $checkInService->checkIn(
            $event,
            Participant::factory()->create(['gender' => ParticipantGender::Female]),
            $actor,
            [ParticipantServiceType::HealthCheck],
        );
        $cancelledParticipant = $cancelledRegistration->eventParticipant->refresh();
        $cancelledTicket = $cancelledRegistration->queueTicket->refresh();

        $this->assertSame(2, $cancelledParticipant->registration_number);
        $this->assertNull($cancelledParticipant->active_registration_number);
        $this->assertSame(QueueTicketStatus::Cancelled, $cancelledTicket->status);
        $this->assertSame(2, $cancelledTicket->number);
        $this->assertNull($cancelledTicket->active_number);
        $this->assertSame(2, $replacement->eventParticipant->registration_number);
        $this->assertSame(2, $replacement->eventParticipant->active_registration_number);
        $this->assertSame('P002', $replacement->registrationNumber);
        $this->assertSame(2, $replacement->queueTicket->number);
        $this->assertSame(2, $replacement->queueTicket->active_number);
    }

    public function test_global_donor_mode_issues_one_sequence_for_all_genders(): void
    {
        EventFacade::fake([
            DashboardUpdated::class,
            ParticipantEligible::class,
            ParticipantMovedToDonation::class,
            QueueUpdated::class,
            TVMonitorUpdated::class,
        ]);
        $actor = User::factory()->create();
        [$event, $screeningPost, $donationPost] = $this->workflow(
            $actor,
            DonorNumberMode::Global,
        );

        $donorTickets = collect([
            Participant::factory()->create(['gender' => ParticipantGender::Male]),
            Participant::factory()->create(['gender' => ParticipantGender::Female]),
            Participant::factory()->create(['gender' => ParticipantGender::Male]),
        ])->map(function (Participant $participant) use (
            $actor,
            $event,
            $screeningPost,
            $donationPost,
        ): QueueTicket {
            $registration = app(CheckInService::class)->checkIn(
                $event,
                $participant,
                $actor,
                [ParticipantServiceType::Donor],
            );
            app(ServiceQueueService::class)->complete(
                $event,
                $screeningPost,
                $registration->queueTicket,
                ['result' => ScreeningResult::Eligible->value],
                $actor,
            );

            return $this->waitingTicket($registration->eventParticipant, $donationPost);
        });

        $this->assertSame(
            [QueueType::DonorGlobal, QueueType::DonorGlobal, QueueType::DonorGlobal],
            $donorTickets->pluck('queue_type')->all(),
        );
        $this->assertSame([1, 2, 3], $donorTickets->pluck('number')->all());
        $this->assertSame(
            ['D001', 'D002', 'D003'],
            $donorTickets->map->formattedNumber()->all(),
        );

        $dashboard = app(DashboardService::class)->snapshot();
        $this->assertSame(3, $dashboard['metrics']['selected_donor']);
        $this->assertSame(3, $dashboard['metrics']['eligible_donor']);
        $this->assertSame(3, $dashboard['metrics']['donor']);

        $monitor = app(MonitorService::class)->snapshot();
        $donationQueue = collect($monitor['queues'])
            ->firstWhere('behavior', ServicePostBehavior::DonationForm->value);
        $this->assertIsArray($donationQueue);
        $this->assertSame(3, $donationQueue['waiting_count']);
        $this->assertSame(
            ['D001', 'D002', 'D003'],
            collect($donationQueue['waiting'])->pluck('number')->all(),
        );

        EventFacade::assertDispatchedTimes(ParticipantEligible::class, 3);
        EventFacade::assertDispatchedTimes(ParticipantMovedToDonation::class, 3);
        EventFacade::assertDispatched(QueueUpdated::class);
        EventFacade::assertDispatched(DashboardUpdated::class);
        EventFacade::assertDispatched(TVMonitorUpdated::class);
    }

    public function test_gender_separated_donor_mode_issues_independent_sequences(): void
    {
        $actor = User::factory()->create();
        [$event, $screeningPost, $donationPost] = $this->workflow(
            $actor,
            DonorNumberMode::GenderSeparated,
        );

        $donorTickets = collect([
            Participant::factory()->create(['gender' => ParticipantGender::Male]),
            Participant::factory()->create(['gender' => ParticipantGender::Male]),
            Participant::factory()->create(['gender' => ParticipantGender::Female]),
            Participant::factory()->create(['gender' => ParticipantGender::Female]),
        ])->map(function (Participant $participant) use (
            $actor,
            $event,
            $screeningPost,
            $donationPost,
        ): QueueTicket {
            $registration = app(CheckInService::class)->checkIn(
                $event,
                $participant,
                $actor,
                [ParticipantServiceType::Donor],
            );
            app(ServiceQueueService::class)->complete(
                $event,
                $screeningPost,
                $registration->queueTicket,
                ['result' => ScreeningResult::Eligible->value],
                $actor,
            );

            return $this->waitingTicket($registration->eventParticipant, $donationPost);
        });

        $this->assertSame(
            [
                QueueType::MaleDonor,
                QueueType::MaleDonor,
                QueueType::FemaleDonor,
                QueueType::FemaleDonor,
            ],
            $donorTickets->pluck('queue_type')->all(),
        );
        $this->assertSame([1, 2, 1, 2], $donorTickets->pluck('number')->all());
        $this->assertSame(
            ['L001', 'L002', 'P001', 'P002'],
            $donorTickets->map->formattedNumber()->all(),
        );
    }

    public function test_donor_number_reuses_the_smallest_available_number_in_its_configured_lane(): void
    {
        $actor = User::factory()->create();
        [$event, , $donationPost] = $this->workflow($actor, DonorNumberMode::Global);

        foreach ([1, 3] as $number) {
            QueueTicket::query()->create([
                'event_id' => $event->id,
                'event_participant_id' => EventParticipant::factory()
                    ->for($event)
                    ->for(Participant::factory())
                    ->create()
                    ->id,
                'service_post_id' => $donationPost->id,
                'queue_type' => QueueType::DonorGlobal,
                'number' => $number,
                'status' => QueueTicketStatus::Waiting,
            ]);
        }

        app(DatabaseManager::class)->transaction(function () use ($event): void {
            $lockedEvent = Event::query()
                ->whereKey($event->id)
                ->lockForUpdate()
                ->firstOrFail();
            $donorNumber = app(QueueNumberGenerator::class)->nextDonor(
                $lockedEvent,
                ParticipantGender::Male,
            );

            $this->assertSame(QueueType::DonorGlobal, $donorNumber->queueType);
            $this->assertSame(2, $donorNumber->number);
        });
    }

    public function test_database_rejects_duplicate_active_queue_number_in_the_same_scope(): void
    {
        $event = Event::factory()->create();
        $post = $this->servicePost(
            $event,
            'health',
            1,
            ServicePostBehavior::HealthForm,
            'H',
        );
        $firstParticipant = EventParticipant::factory()->for($event)->create();
        $secondParticipant = EventParticipant::factory()->for($event)->create();

        QueueTicket::query()->create([
            'event_id' => $event->id,
            'event_participant_id' => $firstParticipant->id,
            'service_post_id' => $post->id,
            'queue_type' => QueueType::General,
            'number' => 1,
            'status' => QueueTicketStatus::Waiting,
        ]);

        $this->expectException(QueryException::class);

        QueueTicket::query()->create([
            'event_id' => $event->id,
            'event_participant_id' => $secondParticipant->id,
            'service_post_id' => $post->id,
            'queue_type' => QueueType::General,
            'number' => 1,
            'status' => QueueTicketStatus::Waiting,
        ]);
    }

    public function test_database_rejects_duplicate_active_registration_number(): void
    {
        $event = Event::factory()->create();

        EventParticipant::factory()
            ->for($event)
            ->for(Participant::factory())
            ->create([
                'registration_number' => 1,
                'active_registration_number' => 1,
            ]);

        $this->expectException(QueryException::class);

        EventParticipant::factory()
            ->for($event)
            ->for(Participant::factory())
            ->create([
                'registration_number' => 1,
                'active_registration_number' => 1,
            ]);
    }

    public function test_main_operational_timestamps_represent_each_distinct_stage(): void
    {
        $actor = User::factory()->create();
        [$event, $screeningPost, $donationPost, $healthPost] = $this->workflow(
            $actor,
            DonorNumberMode::Global,
        );
        $queueService = app(ServiceQueueService::class);

        Carbon::setTestNow('2026-07-30 08:00:00');
        $registration = app(CheckInService::class)->checkIn(
            $event,
            Participant::factory()->create(['gender' => ParticipantGender::Male]),
            $actor,
            [ParticipantServiceType::Donor, ParticipantServiceType::HealthCheck],
        );
        $participant = $registration->eventParticipant->refresh();
        $donorService = $this->selectedService($participant, ParticipantServiceType::Donor);
        $healthService = $this->selectedService($participant, ParticipantServiceType::HealthCheck);

        $this->assertSame('2026-07-30 08:00:00', $participant->checked_in_at?->toDateTimeString());
        $this->assertSame('2026-07-30 08:00:00', $donorService->selected_at->toDateTimeString());
        $this->assertNull($participant->completed_at);
        $this->assertNull($donorService->eligibility_started_at);
        $this->assertNull($donorService->eligibility_completed_at);
        $this->assertNull($donorService->started_at);
        $this->assertNull($donorService->completed_at);
        $this->assertNull($healthService->started_at);
        $this->assertNull($healthService->completed_at);

        Carbon::setTestNow('2026-07-30 08:10:00');
        $queueService->start(
            $event,
            $screeningPost,
            $registration->queueTicket,
            $actor,
        );
        $donorService->refresh();
        $this->assertSame('2026-07-30 08:10:00', $donorService->eligibility_started_at?->toDateTimeString());
        $this->assertNull($donorService->started_at);

        Carbon::setTestNow('2026-07-30 08:20:00');
        $queueService->complete(
            $event,
            $screeningPost,
            $registration->queueTicket,
            ['result' => ScreeningResult::Eligible->value],
            $actor,
        );
        $donorService->refresh();
        $donorTicket = $this->waitingTicket($participant, $donationPost);
        $this->assertSame('2026-07-30 08:20:00', $donorService->eligibility_completed_at?->toDateTimeString());
        $this->assertNull($donorService->started_at);
        $this->assertSame('2026-07-30 08:20:00', $registration->queueTicket->refresh()->finished_at?->toDateTimeString());

        Carbon::setTestNow('2026-07-30 08:30:00');
        $queueService->start($event, $donationPost, $donorTicket, $actor);
        $donorService->refresh();
        $this->assertSame('2026-07-30 08:30:00', $donorService->started_at?->toDateTimeString());
        $this->assertSame('2026-07-30 08:30:00', $donorTicket->refresh()->served_at?->toDateTimeString());

        Carbon::setTestNow('2026-07-30 08:40:00');
        $queueService->complete($event, $donationPost, $donorTicket, [], $actor);
        $donorService->refresh();
        $healthTicket = $this->waitingTicket($participant, $healthPost);
        $this->assertSame('2026-07-30 08:40:00', $donorService->completed_at?->toDateTimeString());
        $this->assertSame('2026-07-30 08:40:00', $donorTicket->refresh()->finished_at?->toDateTimeString());

        Carbon::setTestNow('2026-07-30 08:50:00');
        $queueService->start($event, $healthPost, $healthTicket, $actor);
        $healthService->refresh();
        $this->assertSame('2026-07-30 08:50:00', $healthService->started_at?->toDateTimeString());
        $this->assertSame('2026-07-30 08:50:00', $healthTicket->refresh()->served_at?->toDateTimeString());

        Carbon::setTestNow('2026-07-30 09:00:00');
        $queueService->complete(
            $event,
            $healthPost,
            $healthTicket,
            ['blood_pressure' => '120/80'],
            $actor,
        );
        $participant->refresh();
        $healthService->refresh();
        $this->assertSame('2026-07-30 09:00:00', $healthService->completed_at?->toDateTimeString());
        $this->assertSame('2026-07-30 09:00:00', $healthTicket->refresh()->finished_at?->toDateTimeString());
        $this->assertSame('2026-07-30 09:00:00', $participant->completed_at?->toDateTimeString());

        Carbon::setTestNow();
    }

    /**
     * @return array{Event, ServicePost, ServicePost, ServicePost}
     */
    private function workflow(User $actor, DonorNumberMode $donorNumberMode): array
    {
        $event = Event::factory()->active()->create(['created_by' => $actor->id]);
        $event->settings()->update([
            'registration_number_format' => RegistrationNumberFormat::GenderPrefix,
            'registration_queue_prefix' => 'R',
            'registration_male_prefix' => 'L',
            'registration_female_prefix' => 'P',
            'registration_queue_digits' => 3,
            'donor_number_mode' => $donorNumberMode,
            'donor_queue_prefix' => 'D',
            'donor_queue_digits' => 3,
            'male_donor_queue_prefix' => 'L',
            'female_donor_queue_prefix' => 'P',
        ]);
        $event->load('settings');
        $screening = $this->servicePost(
            $event,
            'eligibility',
            1,
            ServicePostBehavior::ScreeningForm,
            'K',
        );
        $donation = $this->servicePost(
            $event,
            'donation',
            2,
            ServicePostBehavior::DonationForm,
            'D',
        );
        $health = $this->servicePost(
            $event,
            'health',
            3,
            ServicePostBehavior::HealthForm,
            'H',
        );

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

    private function selectedService(
        EventParticipant $participant,
        ParticipantServiceType $service,
    ): EventParticipantService {
        return EventParticipantService::query()
            ->where('event_participant_id', $participant->id)
            ->where('service', $service->value)
            ->firstOrFail();
    }
}
