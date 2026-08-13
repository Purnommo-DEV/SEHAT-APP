<?php

namespace Tests\Feature;

use App\Enums\DonorNumberMode;
use App\Enums\ParticipantGender;
use App\Enums\ParticipantServiceStatus;
use App\Enums\ParticipantServiceType;
use App\Enums\ParticipantStatus;
use App\Enums\QueueTicketStatus;
use App\Enums\QueueType;
use App\Enums\ServicePostBehavior;
use App\Enums\ServicePostType;
use App\Events\DashboardUpdated;
use App\Events\ParticipantDonationCompleted;
use App\Events\ParticipantEligible;
use App\Events\ParticipantMovedToDonation;
use App\Events\ParticipantMovedToEligibility;
use App\Events\ParticipantMovedToHealthCheck;
use App\Events\QueueUpdated;
use App\Events\TVMonitorUpdated;
use App\Models\Event;
use App\Models\Participant;
use App\Models\ServicePost;
use App\Models\User;
use App\Services\CheckIn\CheckInService;
use App\Services\Dashboard\DashboardService;
use App\Services\Monitor\MonitorService;
use App\Services\Operational\OperationalWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class OperationalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_donor_participant_follows_the_final_four_stage_workflow_and_is_audited(): void
    {
        EventFacade::fake([
            ParticipantMovedToEligibility::class,
            ParticipantEligible::class,
            ParticipantMovedToHealthCheck::class,
            ParticipantMovedToDonation::class,
            ParticipantDonationCompleted::class,
            QueueUpdated::class,
            DashboardUpdated::class,
            TVMonitorUpdated::class,
        ]);
        [$actor, $event] = $this->workflow(DonorNumberMode::GenderSeparated);
        $registration = app(CheckInService::class)->checkIn(
            $event,
            Participant::factory()->create(['gender' => ParticipantGender::Male]),
            $actor,
            [ParticipantServiceType::Donor],
        );
        $service = app(OperationalWorkflowService::class);

        $this->assertSame(ParticipantStatus::Waiting, $registration->eventParticipant->status);
        $this->assertSame('L-001', $registration->registrationNumber);

        $eligibility = $service->startEligibility($event, $registration->eventParticipant, $actor);
        $this->assertSame(ParticipantStatus::WaitingScreening, $eligibility->status);
        $this->assertDatabaseHas('queue_tickets', [
            'id' => $registration->queueTicket->id,
            'status' => QueueTicketStatus::Cancelled->value,
        ]);

        $healthCheck = $service->markEligible($event, $registration->eventParticipant, $actor);
        $this->assertSame(ParticipantStatus::HealthCheck, $healthCheck->status);
        $donating = $service->startDonation($event, $registration->eventParticipant, $actor);
        $this->assertSame(ParticipantStatus::Donating, $donating->status);
        $this->assertDatabaseHas('queue_tickets', [
            'event_participant_id' => $registration->eventParticipant->id,
            'queue_type' => QueueType::MaleDonor->value,
            'number' => 1,
            'status' => QueueTicketStatus::Serving->value,
        ]);

        $finished = $service->complete($event, $registration->eventParticipant, $actor);
        $this->assertSame(ParticipantStatus::Finished, $finished->status);
        $this->assertNotNull($finished->completed_at);
        $this->assertDatabaseCount('event_participant_status_histories', 5);
        $this->assertDatabaseHas('audit_logs', [
            'event_id' => $event->id,
            'action' => 'participant.workflow_completed',
        ]);
        EventFacade::assertDispatched(ParticipantMovedToEligibility::class);
        EventFacade::assertDispatched(ParticipantEligible::class);
        EventFacade::assertDispatched(ParticipantMovedToHealthCheck::class);
        EventFacade::assertDispatched(ParticipantMovedToDonation::class);
        EventFacade::assertDispatched(ParticipantDonationCompleted::class);
        EventFacade::assertDispatched(QueueUpdated::class);
        EventFacade::assertDispatched(DashboardUpdated::class);
        EventFacade::assertDispatched(TVMonitorUpdated::class);
    }

    public function test_donor_number_lanes_follow_the_event_mode_and_use_smallest_available_number(): void
    {
        [$actor, $event] = $this->workflow(DonorNumberMode::GenderSeparated);
        $service = app(OperationalWorkflowService::class);
        $numbers = [];

        foreach ([ParticipantGender::Male, ParticipantGender::Male, ParticipantGender::Female, ParticipantGender::Female] as $gender) {
            $registration = app(CheckInService::class)->checkIn(
                $event,
                Participant::factory()->create(['gender' => $gender]),
                $actor,
                [ParticipantServiceType::Donor],
            );
            $service->startEligibility($event, $registration->eventParticipant, $actor);
            $service->markEligible($event, $registration->eventParticipant, $actor);
            $service->startDonation($event, $registration->eventParticipant, $actor);
            $ticket = $registration->eventParticipant->queueTickets()
                ->whereIn('queue_type', [QueueType::MaleDonor->value, QueueType::FemaleDonor->value])
                ->latest('id')
                ->firstOrFail();
            $numbers[] = $ticket->formattedNumber();
        }

        $this->assertSame(['L-001', 'L-002', 'P-001', 'P-002'], $numbers);
    }

    public function test_donor_ticket_reuses_registration_number_when_the_legacy_mode_is_global(): void
    {
        [$actor, $event] = $this->workflow(DonorNumberMode::Global);
        $service = app(OperationalWorkflowService::class);
        $numbers = [];

        foreach ([ParticipantGender::Male, ParticipantGender::Female, ParticipantGender::Male] as $gender) {
            $registration = app(CheckInService::class)->checkIn(
                $event,
                Participant::factory()->create(['gender' => $gender]),
                $actor,
                [ParticipantServiceType::Donor],
            );
            $service->startEligibility($event, $registration->eventParticipant, $actor);
            $service->markEligible($event, $registration->eventParticipant, $actor);
            $service->startDonation($event, $registration->eventParticipant, $actor);
            $numbers[] = $registration->eventParticipant->queueTickets()
                ->whereIn('queue_type', [QueueType::MaleDonor->value, QueueType::FemaleDonor->value])
                ->latest('id')
                ->firstOrFail()
                ->formattedNumber();
        }

        $this->assertSame(['L-001', 'P-001', 'L-002'], $numbers);
    }

    public function test_event_donation_capacity_is_independent_per_gender_and_releases_its_own_slot_after_completion(): void
    {
        [$actor, $event] = $this->workflow(
            DonorNumberMode::GenderSeparated,
            maleCapacity: 2,
            femaleCapacity: 2,
        );
        $service = app(OperationalWorkflowService::class);
        $activeRegistrations = [];

        foreach ([
            ParticipantGender::Male,
            ParticipantGender::Female,
            ParticipantGender::Male,
            ParticipantGender::Female,
        ] as $gender) {
            $registration = app(CheckInService::class)->checkIn(
                $event,
                Participant::factory()->create(['gender' => $gender]),
                $actor,
                [ParticipantServiceType::Donor],
            );
            $service->startEligibility($event, $registration->eventParticipant, $actor);
            $service->markEligible($event, $registration->eventParticipant, $actor);
            $service->startDonation($event, $registration->eventParticipant, $actor);
            $activeRegistrations[] = $registration;
        }

        $this->assertDatabaseCount('queue_tickets', 8);
        $this->assertSame(4, Event::query()->findOrFail($event->id)->eventParticipants()
            ->where('status', ParticipantStatus::Donating->value)
            ->count());
        $this->assertSame([
            'male' => [
                'gender' => 'male',
                'label' => 'Laki-laki',
                'capacity' => 2,
                'active' => 2,
                'active_donations' => 2,
                'available' => 0,
                'available_slots' => 0,
                'is_full' => true,
            ],
            'female' => [
                'gender' => 'female',
                'label' => 'Perempuan',
                'capacity' => 2,
                'active' => 2,
                'active_donations' => 2,
                'available' => 0,
                'available_slots' => 0,
                'is_full' => true,
            ],
            'total' => [
                'capacity' => 4,
                'active' => 4,
                'active_donations' => 4,
                'available' => 0,
                'available_slots' => 0,
                'is_full' => true,
            ],
        ], app(DashboardService::class)->snapshot()['donation_capacity']);

        $waitingForBed = app(CheckInService::class)->checkIn(
            $event,
            Participant::factory()->create(['gender' => ParticipantGender::Male]),
            $actor,
            [ParticipantServiceType::Donor],
        );
        $service->startEligibility($event, $waitingForBed->eventParticipant, $actor);
        $service->markEligible($event, $waitingForBed->eventParticipant, $actor);

        try {
            $service->startDonation($event, $waitingForBed->eventParticipant, $actor);
            $this->fail('Peserta ketiga laki-laki tidak boleh mengambil bed donor laki-laki yang sudah penuh.');
        } catch (ValidationException $exception) {
            $this->assertSame(['participant' => ['Kapasitas donor Laki-laki sudah penuh.']], $exception->errors());
        }

        $this->assertSame(
            ParticipantStatus::HealthCheck,
            $waitingForBed->eventParticipant->fresh()->status,
        );
        $this->assertSame(4, Event::query()->findOrFail($event->id)->eventParticipants()
            ->where('status', ParticipantStatus::Donating->value)
            ->count());

        $service->complete($event, $activeRegistrations[0]->eventParticipant, $actor);
        $service->startDonation($event, $waitingForBed->eventParticipant, $actor);

        $this->assertSame(
            ParticipantStatus::Donating,
            $waitingForBed->eventParticipant->fresh()->status,
        );
        $this->assertSame(4, Event::query()->findOrFail($event->id)->eventParticipants()
            ->where('status', ParticipantStatus::Donating->value)
            ->count());
    }

    public function test_full_male_capacity_does_not_block_female_donor_capacity(): void
    {
        [$actor, $event] = $this->workflow(
            DonorNumberMode::GenderSeparated,
            maleCapacity: 4,
            femaleCapacity: 4,
        );
        $service = app(OperationalWorkflowService::class);

        foreach (range(1, 4) as $index) {
            $registration = app(CheckInService::class)->checkIn(
                $event,
                Participant::factory()->create(['gender' => ParticipantGender::Male]),
                $actor,
                [ParticipantServiceType::Donor],
            );
            $service->startEligibility($event, $registration->eventParticipant, $actor);
            $service->markEligible($event, $registration->eventParticipant, $actor);
            $service->startDonation($event, $registration->eventParticipant, $actor);
        }

        $female = app(CheckInService::class)->checkIn(
            $event,
            Participant::factory()->create(['gender' => ParticipantGender::Female]),
            $actor,
            [ParticipantServiceType::Donor],
        );
        $service->startEligibility($event, $female->eventParticipant, $actor);
        $service->markEligible($event, $female->eventParticipant, $actor);
        $service->startDonation($event, $female->eventParticipant, $actor);

        $capacity = app(DashboardService::class)->snapshot()['donation_capacity'];
        $this->assertSame(4, $capacity['male']['active']);
        $this->assertTrue($capacity['male']['is_full']);
        $this->assertSame(1, $capacity['female']['active']);
        $this->assertSame(3, $capacity['female']['available']);
        $this->assertSame('P-001', $female->eventParticipant->queueTickets()
            ->where('queue_type', QueueType::FemaleDonor->value)
            ->firstOrFail()
            ->formattedNumber());
    }

    public function test_second_male_donor_action_cannot_take_the_same_last_capacity_slot(): void
    {
        [$actor, $event] = $this->workflow(
            DonorNumberMode::GenderSeparated,
            maleCapacity: 1,
            femaleCapacity: 1,
        );
        $workflow = app(OperationalWorkflowService::class);
        $registrations = [];

        foreach (range(1, 2) as $index) {
            $registration = app(CheckInService::class)->checkIn(
                $event,
                Participant::factory()->create(['gender' => ParticipantGender::Male]),
                $actor,
                [ParticipantServiceType::Donor],
            );
            $workflow->startEligibility($event, $registration->eventParticipant, $actor);
            $workflow->markEligible($event, $registration->eventParticipant, $actor);
            $registrations[] = $registration;
        }

        $workflow->startDonation($event, $registrations[0]->eventParticipant, $actor);

        try {
            $workflow->startDonation($event, $registrations[1]->eventParticipant, $actor);
            $this->fail('Aksi donor kedua tidak boleh mengambil slot laki-laki yang sama.');
        } catch (ValidationException $exception) {
            $this->assertSame(['participant' => ['Kapasitas donor Laki-laki sudah penuh.']], $exception->errors());
        }

        $this->assertSame(ParticipantStatus::Donating, $registrations[0]->eventParticipant->fresh()->status);
        $this->assertSame(ParticipantStatus::HealthCheck, $registrations[1]->eventParticipant->fresh()->status);
        $this->assertSame(1, Event::query()->findOrFail($event->id)->eventParticipants()
            ->where('status', ParticipantStatus::Donating->value)
            ->count());
    }

    public function test_health_only_participant_cannot_consume_a_donor_bed(): void
    {
        [$actor, $event] = $this->workflow(DonorNumberMode::Global, maleCapacity: 1, femaleCapacity: 1);
        $registration = app(CheckInService::class)->checkIn(
            $event,
            Participant::factory()->create(),
            $actor,
            [ParticipantServiceType::HealthCheck],
        );
        $service = app(OperationalWorkflowService::class);

        $service->startHealthCheck($event, $registration->eventParticipant, $actor);

        try {
            $service->startDonation($event, $registration->eventParticipant, $actor);
            $this->fail('Peserta tanpa layanan donor tidak boleh memasuki donor.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['participant' => ['Peserta tidak memilih layanan Donor Darah.']],
                $exception->errors(),
            );
        }

        $this->assertSame(
            ParticipantStatus::HealthCheck,
            $registration->eventParticipant->fresh()->status,
        );
        $this->assertSame(0, app(DashboardService::class)->snapshot()['donation_capacity']['total']['active']);
    }

    public function test_participant_can_complete_from_before_donor_without_creating_a_donor_ticket(): void
    {
        [$actor, $event] = $this->workflow(DonorNumberMode::Global);
        $registration = app(CheckInService::class)->checkIn(
            $event,
            Participant::factory()->create(),
            $actor,
            [ParticipantServiceType::HealthCheck],
        );
        $service = app(OperationalWorkflowService::class);

        $service->startHealthCheck($event, $registration->eventParticipant, $actor);
        $finished = $service->completeBeforeDonation($event, $registration->eventParticipant, $actor);

        $this->assertSame(ParticipantStatus::Finished, $finished->status);
        $this->assertDatabaseMissing('queue_tickets', [
            'event_participant_id' => $registration->eventParticipant->id,
            'queue_type' => QueueType::DonorGlobal->value,
        ]);
    }

    public function test_donor_participant_can_be_marked_ineligible_without_creating_a_donor_ticket(): void
    {
        [$actor, $event] = $this->workflow(DonorNumberMode::Global);
        $registration = app(CheckInService::class)->checkIn(
            $event,
            Participant::factory()->create(),
            $actor,
            [ParticipantServiceType::Donor],
        );
        $service = app(OperationalWorkflowService::class);

        $service->startEligibility($event, $registration->eventParticipant, $actor);
        $finished = $service->markIneligible($event, $registration->eventParticipant, $actor);

        $this->assertSame(ParticipantStatus::Finished, $finished->status);
        $this->assertDatabaseHas('event_participant_services', [
            'event_participant_id' => $registration->eventParticipant->id,
            'service' => ParticipantServiceType::Donor->value,
            'status' => ParticipantServiceStatus::NotEligible->value,
        ]);
        $this->assertDatabaseMissing('queue_tickets', [
            'event_participant_id' => $registration->eventParticipant->id,
            'queue_type' => QueueType::DonorGlobal->value,
        ]);
    }

    public function test_second_operational_action_is_rejected_after_first_action_has_changed_status(): void
    {
        [$actor, $event] = $this->workflow(DonorNumberMode::Global);
        $registration = app(CheckInService::class)->checkIn(
            $event,
            Participant::factory()->create(),
            $actor,
            [ParticipantServiceType::Donor],
        );
        $service = app(OperationalWorkflowService::class);
        $service->startEligibility($event, $registration->eventParticipant, $actor);

        try {
            $service->startEligibility($event, $registration->eventParticipant, $actor);
            $this->fail('Transition kedua seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertSame('Status peserta sudah berubah menjadi Cek Kelayakan Donor.', $exception->errors()['participant'][0]);
        }

        $this->assertDatabaseCount('event_participant_status_histories', 2);
    }

    public function test_operational_buttons_post_to_the_transition_endpoints_without_a_page_reload(): void
    {
        [$actor, $event] = $this->workflow(DonorNumberMode::Global, true);
        $registration = app(CheckInService::class)->checkIn(
            $event,
            Participant::factory()->create(),
            $actor,
            [ParticipantServiceType::Donor],
        );

        $this->postJson(route('events.operations.eligibility.start', [$event, $registration->eventParticipant]))
            ->assertOk()
            ->assertJsonPath('data.status', ParticipantStatus::WaitingScreening->value);
        $this->postJson(route('events.operations.eligibility.eligible', [$event, $registration->eventParticipant]))
            ->assertOk()
            ->assertJsonPath('data.status', ParticipantStatus::HealthCheck->value)
            ->assertJsonPath('data.eligibility.result', 'eligible');
        $this->postJson(route('events.operations.donating.start', [$event, $registration->eventParticipant]))
            ->assertOk()
            ->assertJsonPath('data.status', ParticipantStatus::Donating->value);
        $this->postJson(route('events.operations.completed.store', [$event, $registration->eventParticipant]))
            ->assertOk()
            ->assertJsonPath('data.status', ParticipantStatus::Finished->value);
    }

    public function test_single_operational_screen_is_ordered_by_global_registration_order_and_exposes_eligibility_actions(): void
    {
        [$actor, $event] = $this->workflow(DonorNumberMode::Global, true);
        $second = app(CheckInService::class)->checkIn($event, Participant::factory()->create(['gender' => ParticipantGender::Male]), $actor, [ParticipantServiceType::Donor]);
        $first = app(CheckInService::class)->checkIn($event, Participant::factory()->create(['gender' => ParticipantGender::Male]), $actor, [ParticipantServiceType::Donor]);
        $this->assertSame(1, $second->eventParticipant->registration_number);
        $this->assertSame(2, $first->eventParticipant->registration_number);

        $this->get(route('events.operations.index', $event))
            ->assertRedirect(route('events.operations.waiting.desk', $event));

        $this->get(route('events.operations.waiting', $event))
            ->assertRedirect(route('events.operations.waiting.desk', $event));

        $response = $this->get(route('events.operations.waiting.desk', $event));

        $response->assertOk()
            ->assertSee('Operasional')
            ->assertSee('NEXT')
            ->assertSee('SKIP')
            ->assertSee('GOTO')
            ->assertSee('Antrean berikutnya')
            ->assertSee('Cek Kelayakan Donor')
            ->assertSee('LAYAK DONOR')
            ->assertSee('TIDAK LAYAK DONOR');
        $this->assertLessThan(
            strpos($response->getContent(), 'L-002'),
            strpos($response->getContent(), 'L-001'),
        );

        app(OperationalWorkflowService::class)->startEligibility($event, $second->eventParticipant, $actor);

        $this->get(route('events.operations.health-check', $event))
            ->assertRedirect(route('events.operations.waiting.desk', $event));
        $this->get(route('events.operations.before-donor', $event))
            ->assertRedirect(route('events.operations.waiting.desk', $event));
    }

    public function test_starting_health_check_never_completes_a_participant_without_an_explicit_action(): void
    {
        [$actor, $event] = $this->workflow(DonorNumberMode::Global, true);
        $registration = app(CheckInService::class)->checkIn(
            $event,
            Participant::factory()->create(),
            $actor,
            [ParticipantServiceType::HealthCheck],
        );

        $this->postJson(route('events.operations.health-check.start', [$event, $registration->eventParticipant]))
            ->assertOk()
            ->assertJsonPath('data.status', ParticipantStatus::HealthCheck->value)
            ->assertJsonPath('data.position.value', ParticipantStatus::HealthCheck->value)
            ->assertJsonPath('data.position.label', 'Cek Kesehatan');

        $this->assertDatabaseHas('event_participants', [
            'id' => $registration->eventParticipant->id,
            'status' => ParticipantStatus::HealthCheck->value,
            'completed_at' => null,
        ]);
        $this->assertDatabaseHas('event_participant_status_histories', [
            'event_participant_id' => $registration->eventParticipant->id,
            'from_status' => ParticipantStatus::Waiting->value,
            'to_status' => ParticipantStatus::HealthCheck->value,
        ]);
        $this->assertDatabaseMissing('event_participant_status_histories', [
            'event_participant_id' => $registration->eventParticipant->id,
            'to_status' => ParticipantStatus::Finished->value,
        ]);
    }

    public function test_transition_changes_only_the_previous_stage_and_keeps_the_current_position_visible(): void
    {
        [$actor, $event] = $this->workflow(DonorNumberMode::GenderSeparated);
        $registration = app(CheckInService::class)->checkIn(
            $event,
            Participant::factory()->create(['gender' => ParticipantGender::Male]),
            $actor,
            [ParticipantServiceType::Donor, ParticipantServiceType::HealthCheck],
        );
        $participantId = $registration->eventParticipant->id;

        $this->getJson(route('events.operations.data', [$event, ParticipantStatus::Waiting->value]))
            ->assertOk()
            ->assertJsonPath('data.0.id', $participantId);

        $this->postJson(route('events.operations.eligibility.start', [$event, $registration->eventParticipant]))
            ->assertOk();

        $this->getJson(route('events.operations.data', [$event, ParticipantStatus::Waiting->value]))
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson(route('events.operations.data', [$event, ParticipantStatus::WaitingScreening->value]))
            ->assertOk()
            ->assertJsonPath('data.0.id', $participantId);
        $this->getJson(route('events.operations.waiting.snapshot', $event))
            ->assertOk()
            ->assertJsonCount(0, 'queue.tickets')
            ->assertJsonPath('queue.positions.0.id', $participantId)
            ->assertJsonPath('queue.positions.0.position.value', ParticipantStatus::WaitingScreening->value);
        $this->getJson(route('dashboard.data'))
            ->assertOk()
            ->assertJsonPath('metrics.eligibility', 1)
            ->assertJsonPath('current_positions.0.status', ParticipantStatus::WaitingScreening->value);
        $this->getJson(route('events.monitor.data', $event))
            ->assertOk()
            ->assertJsonPath('queues.0.active_positions.0.id', $participantId)
            ->assertJsonPath('queues.0.active_positions.0.position', ParticipantStatus::WaitingScreening->value);

        $this->postJson(route('events.operations.eligibility.eligible', [$event, $registration->eventParticipant]))
            ->assertOk();

        $this->getJson(route('events.operations.data', [$event, ParticipantStatus::WaitingScreening->value]))
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson(route('events.operations.data', [$event, ParticipantStatus::HealthCheck->value]))
            ->assertOk()
            ->assertJsonPath('data.0.id', $participantId)
            ->assertJsonPath('data.0.eligibility.result', 'eligible');

        $this->postJson(route('events.operations.donating.start', [$event, $registration->eventParticipant]))
            ->assertOk();

        $this->getJson(route('events.operations.data', [$event, ParticipantStatus::HealthCheck->value]))
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson(route('events.operations.data', [$event, ParticipantStatus::Donating->value]))
            ->assertOk()
            ->assertJsonPath('data.0.id', $participantId);
        $this->getJson(route('events.operations.waiting.snapshot', $event))
            ->assertOk()
            ->assertJsonPath('queue.positions.0.position.value', ParticipantStatus::Donating->value);
        $this->getJson(route('events.monitor.data', $event))
            ->assertOk()
            ->assertJsonPath('queues.0.active_positions.0.position', ParticipantStatus::Donating->value);

        $this->postJson(route('events.operations.completed.store', [$event, $registration->eventParticipant]))
            ->assertOk();

        $this->getJson(route('events.operations.data', [$event, ParticipantStatus::Donating->value]))
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson(route('events.operations.data', [$event, ParticipantStatus::Finished->value]))
            ->assertOk()
            ->assertJsonPath('data.0.id', $participantId);
        $this->getJson(route('events.operations.waiting.snapshot', $event))
            ->assertOk()
            ->assertJsonCount(0, 'queue.positions');
        $this->getJson(route('dashboard.data'))
            ->assertOk()
            ->assertJsonPath('metrics.finished', 1)
            ->assertJsonPath('current_positions.0.status', ParticipantStatus::Finished->value);
        $this->getJson(route('events.monitor.data', $event))
            ->assertOk()
            ->assertJsonCount(0, 'queues.0.active_positions');
    }

    public function test_before_donor_data_keeps_selected_services_separate_from_the_current_position(): void
    {
        [$actor, $event] = $this->workflow(DonorNumberMode::Global, true);
        $donorOnly = app(CheckInService::class)->checkIn($event, Participant::factory()->create(), $actor, [ParticipantServiceType::Donor]);
        $healthOnly = app(CheckInService::class)->checkIn($event, Participant::factory()->create(), $actor, [ParticipantServiceType::HealthCheck]);
        $both = app(CheckInService::class)->checkIn($event, Participant::factory()->create(), $actor, [ParticipantServiceType::Donor, ParticipantServiceType::HealthCheck]);
        $workflow = app(OperationalWorkflowService::class);

        $workflow->startEligibility($event, $donorOnly->eventParticipant, $actor);
        $workflow->startHealthCheck($event, $healthOnly->eventParticipant, $actor);
        $workflow->startEligibility($event, $both->eventParticipant, $actor);

        $eligibilityPayload = collect($this->getJson(route('events.operations.data', [$event, ParticipantStatus::WaitingScreening->value]))
            ->assertOk()
            ->json('data'))
            ->keyBy('id');

        $this->assertSame('Cek Kelayakan Donor', $eligibilityPayload[$donorOnly->eventParticipant->id]['position']['label']);
        $this->assertSame(['Donor Darah'], array_column($eligibilityPayload[$donorOnly->eventParticipant->id]['services'], 'label'));
        $this->assertTrue($eligibilityPayload[$donorOnly->eventParticipant->id]['can_decide_eligibility']);
        $this->assertSame(['Donor Darah', 'Pemeriksaan Kesehatan'], array_column($eligibilityPayload[$both->eventParticipant->id]['services'], 'label'));
        $this->assertTrue($eligibilityPayload[$both->eventParticipant->id]['can_decide_eligibility']);

        $payload = collect($this->getJson(route('events.operations.data', [$event, ParticipantStatus::HealthCheck->value]))
            ->assertOk()
            ->json('data'))
            ->keyBy('id');

        $this->assertSame(['Pemeriksaan Kesehatan'], array_column($payload[$healthOnly->eventParticipant->id]['services'], 'label'));
        $this->assertFalse($payload[$healthOnly->eventParticipant->id]['can_start_eligibility']);
        $this->assertTrue($payload[$healthOnly->eventParticipant->id]['can_complete_before_donation']);

    }

    public function test_dashboard_and_tv_monitor_follow_operational_state_and_the_waiting_call(): void
    {
        [$actor, $event] = $this->workflow(DonorNumberMode::GenderSeparated);
        $registration = app(CheckInService::class)->checkIn(
            $event,
            Participant::factory()->create(['gender' => ParticipantGender::Female]),
            $actor,
            [ParticipantServiceType::Donor, ParticipantServiceType::HealthCheck],
        );
        $service = app(OperationalWorkflowService::class);
        $service->startEligibility($event, $registration->eventParticipant, $actor);
        $service->markEligible($event, $registration->eventParticipant, $actor);
        $service->startDonation($event, $registration->eventParticipant, $actor);

        $waitingRegistration = app(CheckInService::class)->checkIn(
            $event,
            Participant::factory()->create(['gender' => ParticipantGender::Female]),
            $actor,
            [ParticipantServiceType::HealthCheck],
        );
        $waitingPost = ServicePost::query()
            ->where('event_id', $event->id)
            ->where('behavior', ServicePostBehavior::HealthForm->value)
            ->firstOrFail();
        $this->postJson(route('events.service-queues.call', [$event, $waitingPost, $waitingRegistration->queueTicket]))
            ->assertOk();

        $dashboard = app(DashboardService::class)->snapshot();
        $monitor = app(MonitorService::class)->snapshot();

        $this->assertSame(2, $dashboard['metrics']['checked_in']);
        $this->assertSame(1, $dashboard['metrics']['donating']);
        $this->assertSame(1, $dashboard['metrics']['selected_both']);
        $this->assertContains(
            $registration->eventParticipant->load('participant')->participant->name,
            array_column($dashboard['activities'], 'subject_name'),
        );
        $femaleLane = collect($monitor['queues'])->firstWhere('id', ParticipantGender::Female->value);
        $this->assertSame(
            $waitingRegistration->eventParticipant->load('participant')->formattedRegistrationNumber($event->settings),
            $femaleLane['current']['number'],
        );
        $this->assertSame('Perempuan', $femaleLane['label']);
        $this->assertContains(
            ParticipantStatus::Donating->value,
            array_column($femaleLane['active_positions'], 'position'),
        );
    }

    /** @return array{User, Event} */
    private function workflow(
        DonorNumberMode $mode,
        bool $withPermission = false,
        int $maleCapacity = 4,
        int $femaleCapacity = 4,
    ): array {
        $actor = User::factory()->create();
        if ($withPermission) {
            $permission = Permission::query()->firstOrCreate([
                'name' => 'operations.manage',
                'guard_name' => 'web',
            ]);
            $actor->givePermissionTo($permission);
        }
        $event = Event::factory()->active()->for($actor, 'creator')->create();
        $event->settings()->update([
            'donor_number_mode' => $mode->value,
            'donor_queue_prefix' => 'D',
            'male_donor_queue_prefix' => 'L',
            'female_donor_queue_prefix' => 'P',
            'donation_capacity_male' => $maleCapacity,
            'donation_capacity_female' => $femaleCapacity,
        ]);
        ServicePost::factory()->for($event)->state([
            'type' => ServicePostType::Health,
            'behavior' => ServicePostBehavior::HealthForm,
            'sequence' => 1,
            'is_active' => true,
        ])->create();
        ServicePost::factory()->for($event)->state([
            'type' => ServicePostType::Screening,
            'behavior' => ServicePostBehavior::ScreeningForm,
            'sequence' => 2,
            'is_active' => true,
        ])->create();
        ServicePost::factory()->for($event)->state([
            'type' => ServicePostType::Donation,
            'behavior' => ServicePostBehavior::DonationForm,
            'sequence' => 3,
            'is_active' => true,
        ])->create();

        return [$actor, $event->fresh('settings')];
    }
}
