<?php

namespace Tests\Feature;

use App\Enums\DonorNumberMode;
use App\Enums\EventStatus;
use App\Enums\ParticipantGender;
use App\Enums\ParticipantServiceType;
use App\Enums\ParticipantStatus;
use App\Enums\RegistrationNumberFormat;
use App\Enums\ServicePostBehavior;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Participant;
use App\Models\ServicePost;
use App\Models\User;
use App\Services\CheckIn\CheckInService;
use App\Services\Operational\DonationCapacityService;
use App\Services\Operational\OperationalWorkflowService;
use Database\Seeders\AdminSeeder;
use Database\Seeders\DefaultActiveEventSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DefaultActiveEventSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_seed_creates_an_active_operational_demo_event(): void
    {
        $this->seed(DefaultActiveEventSeeder::class);

        $administrator = User::query()
            ->where('email', AdminSeeder::EMAIL)
            ->firstOrFail();
        $event = Event::query()
            ->with(['settings', 'donationCapacityLanes', 'servicePosts'])
            ->where('code', DefaultActiveEventSeeder::EVENT_CODE)
            ->firstOrFail();

        $this->assertTrue($administrator->hasRole(UserRole::Administrator->value));
        $this->assertSame(DefaultActiveEventSeeder::EVENT_NAME, $event->name);
        $this->assertSame(EventStatus::Active, $event->status);
        $this->assertSame('active', $event->active_marker);
        $this->assertSame(RegistrationNumberFormat::GenderPrefix, $event->settings->registration_number_format);
        $this->assertSame(DonorNumberMode::GenderSeparated, $event->settings->donor_number_mode);
        $this->assertSame('L', $event->settings->registration_male_prefix);
        $this->assertSame('P', $event->settings->registration_female_prefix);
        $this->assertSame(3, $event->settings->registration_queue_digits);
        $this->assertSame(4, $event->settings->donation_capacity_male);
        $this->assertSame(4, $event->settings->donation_capacity_female);
        $this->assertCount(2, $event->donationCapacityLanes);
        $this->assertCount(3, $event->servicePosts);
        $this->assertTrue($event->servicePosts->contains('behavior', ServicePostBehavior::HealthForm));
        $this->assertTrue($event->servicePosts->contains('behavior', ServicePostBehavior::ScreeningForm));
        $this->assertTrue($event->servicePosts->contains('behavior', ServicePostBehavior::DonationForm));
        $this->assertSame(10, Participant::query()->count());
        $this->assertSame(0, EventParticipant::query()->count());
    }

    public function test_default_seed_is_idempotent_and_event_is_ready_for_registration_and_operations(): void
    {
        $this->seed(DefaultActiveEventSeeder::class);
        $this->seed(DefaultActiveEventSeeder::class);

        $event = Event::query()->where('code', DefaultActiveEventSeeder::EVENT_CODE)->firstOrFail();
        $healthPost = ServicePost::query()
            ->where('event_id', $event->id)
            ->where('behavior', ServicePostBehavior::HealthForm->value)
            ->firstOrFail();
        $participant = Participant::query()
            ->where('name', 'Peserta Laki 01')
            ->firstOrFail();
        $femaleParticipant = Participant::query()
            ->where('name', 'Peserta Perempuan 01')
            ->firstOrFail();

        $this->assertSame(1, Event::query()->where('code', DefaultActiveEventSeeder::EVENT_CODE)->count());
        $this->assertSame(1, $event->settings()->count());
        $this->assertSame(3, $event->servicePosts()->count());
        $this->assertSame(2, $event->donationCapacityLanes()->count());
        $this->assertSame(10, Participant::query()->count());
        $this->get(route('check-ins.active'))
            ->assertRedirect(route('events.check-ins.index', $event));
        $this->get(route('operations.active'))
            ->assertRedirect(route('events.operations.waiting.desk', $event));
        $this->get(route('events.check-ins.index', $event))
            ->assertOk()
            ->assertSee(DefaultActiveEventSeeder::EVENT_NAME);
        $this->get(route('events.operations.waiting.desk', $event))
            ->assertOk()
            ->assertSee('NEXT');

        $this->postJson(route('events.check-ins.store', $event), [
            'participant_id' => $participant->id,
            'services' => [ParticipantServiceType::Donor->value],
        ])->assertCreated()
            ->assertJsonPath('data.registration_number', 'L-001');
        $this->postJson(route('events.check-ins.store', $event), [
            'participant_id' => $femaleParticipant->id,
            'services' => [ParticipantServiceType::HealthCheck->value],
        ])->assertCreated()
            ->assertJsonPath('data.registration_number', 'P-001');

        $eventParticipant = EventParticipant::query()
            ->where('event_id', $event->id)
            ->where('participant_id', $participant->id)
            ->firstOrFail();
        $ticket = $eventParticipant->queueTickets()->firstOrFail();

        $this->assertSame(ParticipantStatus::Waiting, $eventParticipant->status);
        $this->postJson(route('events.service-queues.call', [$event, $healthPost, $ticket]))
            ->assertOk()
            ->assertJsonPath('data.status', 'calling');
        $this->assertSame(ParticipantStatus::Calling, $eventParticipant->fresh()->status);
    }

    public function test_database_seeded_demo_supports_eligible_ineligible_and_health_only_simulations(): void
    {
        $this->seed();

        $event = Event::query()->where('code', DefaultActiveEventSeeder::EVENT_CODE)->firstOrFail();
        $healthPost = ServicePost::query()
            ->where('event_id', $event->id)
            ->where('behavior', ServicePostBehavior::HealthForm->value)
            ->firstOrFail();
        $eligibleParticipant = Participant::query()->where('name', 'Peserta Laki 01')->firstOrFail();
        $ineligibleParticipant = Participant::query()->where('name', 'Peserta Laki 02')->firstOrFail();
        $healthOnlyParticipant = Participant::query()->where('name', 'Peserta Perempuan 01')->firstOrFail();

        $this->postJson(route('events.check-ins.store', $event), [
            'participant_id' => $eligibleParticipant->id,
            'services' => [ParticipantServiceType::Donor->value],
        ])->assertCreated()
            ->assertJsonPath('data.registration_number', 'L-001')
            ->assertJsonPath('data.registration_order', 1);
        $this->postJson(route('events.check-ins.store', $event), [
            'participant_id' => $ineligibleParticipant->id,
            'services' => [ParticipantServiceType::Donor->value],
        ])->assertCreated()
            ->assertJsonPath('data.registration_number', 'L-002')
            ->assertJsonPath('data.registration_order', 2);
        $this->postJson(route('events.check-ins.store', $event), [
            'participant_id' => $healthOnlyParticipant->id,
            'services' => [ParticipantServiceType::HealthCheck->value],
        ])->assertCreated()
            ->assertJsonPath('data.registration_number', 'P-001')
            ->assertJsonPath('data.registration_order', 3);

        $eligible = EventParticipant::query()->where('participant_id', $eligibleParticipant->id)->firstOrFail();
        $ineligible = EventParticipant::query()->where('participant_id', $ineligibleParticipant->id)->firstOrFail();
        $healthOnly = EventParticipant::query()->where('participant_id', $healthOnlyParticipant->id)->firstOrFail();

        $this->postJson(route('events.service-queues.call', [$event, $healthPost, $eligible->queueTickets()->firstOrFail()]))->assertOk();
        $this->postJson(route('events.operations.eligibility.start', [$event, $eligible]))->assertOk();
        $this->postJson(route('events.operations.eligibility.eligible', [$event, $eligible]))
            ->assertOk()
            ->assertJsonPath('data.status', ParticipantStatus::HealthCheck->value);
        $this->postJson(route('events.operations.donating.start', [$event, $eligible]))
            ->assertOk()
            ->assertJsonPath('data.status', ParticipantStatus::Donating->value);
        $this->postJson(route('events.operations.completed.store', [$event, $eligible]))
            ->assertOk()
            ->assertJsonPath('data.status', ParticipantStatus::Finished->value);

        $this->postJson(route('events.service-queues.call', [$event, $healthPost, $ineligible->queueTickets()->firstOrFail()]))->assertOk();
        $this->postJson(route('events.operations.eligibility.start', [$event, $ineligible]))->assertOk();
        $this->postJson(route('events.operations.eligibility.ineligible', [$event, $ineligible]))
            ->assertOk()
            ->assertJsonPath('data.status', ParticipantStatus::Finished->value);

        $this->postJson(route('events.service-queues.call', [$event, $healthPost, $healthOnly->queueTickets()->firstOrFail()]))->assertOk();
        $this->postJson(route('events.operations.health-check.start', [$event, $healthOnly]))->assertOk();
        $this->postJson(route('events.operations.health-check.complete', [$event, $healthOnly]))
            ->assertOk()
            ->assertJsonPath('data.status', ParticipantStatus::Finished->value);

        $this->assertSame(0, app(DonationCapacityService::class)->snapshot($event)->toArray()['total']['active']);
        $this->assertDatabaseMissing('queue_tickets', [
            'event_participant_id' => $ineligible->id,
            'service_post_id' => ServicePost::query()
                ->where('event_id', $event->id)
                ->where('behavior', ServicePostBehavior::DonationForm->value)
                ->value('id'),
        ]);
    }

    public function test_demo_male_donation_capacity_is_four_and_releases_a_bed_for_the_fifth_participant(): void
    {
        $this->seed();

        $event = Event::query()->where('code', DefaultActiveEventSeeder::EVENT_CODE)->firstOrFail();
        $administrator = User::query()->where('email', AdminSeeder::EMAIL)->firstOrFail();
        $participants = Participant::query()
            ->where('gender', ParticipantGender::Male->value)
            ->orderBy('name')
            ->get();

        $this->assertCount(5, $participants);

        $checkIn = app(CheckInService::class);
        $workflow = app(OperationalWorkflowService::class);
        $registrations = [];

        foreach ($participants as $participant) {
            $registration = $checkIn->checkIn(
                $event,
                $participant,
                $administrator,
                [ParticipantServiceType::Donor],
            );
            $workflow->startEligibility($event, $registration->eventParticipant, $administrator);
            $workflow->markEligible($event, $registration->eventParticipant, $administrator);
            $registrations[] = $registration;
        }

        foreach (array_slice($registrations, 0, 4) as $registration) {
            $workflow->startDonation($event, $registration->eventParticipant, $administrator);
        }

        $fifthRegistration = $registrations[4];

        try {
            $workflow->startDonation($event, $fifthRegistration->eventParticipant, $administrator);
            $this->fail('Peserta kelima laki-laki tidak boleh mengambil bed donor laki-laki yang sudah penuh.');
        } catch (ValidationException $exception) {
            $this->assertSame(['participant' => ['Kapasitas donor Laki-laki sudah penuh.']], $exception->errors());
        }

        $capacity = app(DonationCapacityService::class)->snapshot($event)->toArray();
        $this->assertSame(4, $capacity['male']['active']);
        $this->assertSame(0, $capacity['male']['available']);
        $this->assertSame(ParticipantStatus::HealthCheck, $fifthRegistration->eventParticipant->fresh()->status);

        $workflow->complete($event, $registrations[0]->eventParticipant, $administrator);
        $workflow->startDonation($event, $fifthRegistration->eventParticipant, $administrator);

        $capacityAfterRelease = app(DonationCapacityService::class)->snapshot($event)->toArray();
        $this->assertSame(4, $capacityAfterRelease['male']['active']);
        $this->assertSame(ParticipantStatus::Donating, $fifthRegistration->eventParticipant->fresh()->status);
    }
}
