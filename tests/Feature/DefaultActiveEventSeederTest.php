<?php

namespace Tests\Feature;

use App\Enums\DonorNumberMode;
use App\Enums\EventStatus;
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
use Database\Seeders\AdminSeeder;
use Database\Seeders\DefaultActiveEventSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertCount(2, $event->servicePosts);
        $this->assertTrue($event->servicePosts->contains('behavior', ServicePostBehavior::HealthForm));
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
        $this->assertSame(2, $event->servicePosts()->count());
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
            ->assertJsonPath('data.registration_number', 'L001');
        $this->postJson(route('events.check-ins.store', $event), [
            'participant_id' => $femaleParticipant->id,
            'services' => [ParticipantServiceType::HealthCheck->value],
        ])->assertCreated()
            ->assertJsonPath('data.registration_number', 'P001');

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
}
