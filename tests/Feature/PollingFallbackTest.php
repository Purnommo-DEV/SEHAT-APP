<?php

namespace Tests\Feature;

use App\Enums\ParticipantGender;
use App\Enums\ParticipantServiceType;
use App\Enums\ParticipantStatus;
use App\Enums\ServicePostBehavior;
use App\Enums\ServicePostType;
use App\Models\Event;
use App\Models\Participant;
use App\Models\ServicePost;
use App\Services\CheckIn\CheckInService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PollingFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_polling_mode_renders_the_single_operational_screen_without_initializing_echo(): void
    {
        config()->set('foundation.realtime.driver', 'polling');
        config()->set('foundation.realtime.polling_interval_ms', 3000);
        $event = $this->activeEvent();

        foreach ([
            route('events.check-ins.index', $event),
            route('events.operations.waiting.desk', $event),
            route('dashboard'),
            route('events.monitor.show', $event),
        ] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('data-realtime-driver="polling"', false)
                ->assertSee('data-polling-interval-ms="3000"', false);
        }

        foreach ([
            route('events.operations.waiting', $event),
            route('events.operations.health-check', $event),
            route('events.operations.before-donor', $event),
            route('events.operations.donating', $event),
            route('events.operations.completed', $event),
        ] as $url) {
            $this->get($url)->assertRedirect(route('events.operations.waiting.desk', $event));
        }

        $this->get(route('events.check-ins.index', $event))
            ->assertSee('Diperbarui berkala setiap 3 detik');
        $this->get(route('events.monitor.show', $event))
            ->assertSee('Data diperbarui berkala setiap 3 detik');

        $echoSource = File::get(resource_path('js/echo.js'));
        $appSource = File::get(resource_path('js/app.js'));

        $this->assertStringContainsString("if (realtimeDriver === 'reverb')", $echoSource);
        $this->assertStringContainsString('window.setInterval(() => component[method](), pollingIntervalMs())', $appSource);
        $this->assertStringNotContainsString('location.reload', $appSource);
    }

    public function test_polling_endpoints_return_the_latest_operational_state_for_each_screen(): void
    {
        config()->set('foundation.realtime.driver', 'polling');
        $event = $this->activeEvent();
        $registration = app(CheckInService::class)->checkIn(
            $event,
            Participant::factory()->create(['gender' => ParticipantGender::Male]),
            null,
            [ParticipantServiceType::Donor, ParticipantServiceType::HealthCheck],
        );

        $this->getJson(route('events.check-ins.data', $event))
            ->assertOk()
            ->assertJsonPath('data.0.participant.id', $registration->eventParticipant->participant_id);
        $this->getJson(route('events.operations.waiting.snapshot', $event))
            ->assertOk()
            ->assertJsonPath('queue.tickets.0.participant.id', $registration->eventParticipant->participant_id);
        $this->getJson(route('events.operations.data', [$event, ParticipantStatus::Waiting->value]))
            ->assertOk()
            ->assertJsonPath('data.0.id', $registration->eventParticipant->id);

        $healthPost = ServicePost::query()
            ->where('event_id', $event->id)
            ->where('behavior', ServicePostBehavior::HealthForm->value)
            ->firstOrFail();
        $this->postJson(route('events.service-queues.call', [$event, $healthPost, $registration->queueTicket]))
            ->assertOk()
            ->assertJsonPath('data.status', 'calling');
        $this->postJson(route('events.operations.health-check.start', [$event, $registration->eventParticipant]))
            ->assertOk()
            ->assertJsonPath('data.status', ParticipantStatus::HealthCheck->value);
        $this->getJson(route('events.operations.waiting.snapshot', $event))
            ->assertOk()
            ->assertJsonCount(0, 'queue.tickets')
            ->assertJsonPath('queue.positions.0.id', $registration->eventParticipant->id)
            ->assertJsonPath('queue.positions.0.position.value', ParticipantStatus::HealthCheck->value);
        $this->getJson(route('events.operations.data', [$event, ParticipantStatus::Waiting->value]))
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson(route('events.operations.data', [$event, ParticipantStatus::HealthCheck->value]))
            ->assertOk()
            ->assertJsonPath('data.0.id', $registration->eventParticipant->id)
            ->assertJsonPath('meta.donation_capacity.male.capacity', 4)
            ->assertJsonPath('meta.donation_capacity.male.active', 0)
            ->assertJsonPath('meta.donation_capacity.male.available', 4)
            ->assertJsonPath('meta.donation_capacity.female.capacity', 4);

        $this->postJson(route('events.operations.eligibility.start', [$event, $registration->eventParticipant]))
            ->assertOk()
            ->assertJsonPath('data.status', ParticipantStatus::WaitingScreening->value);
        $this->postJson(route('events.operations.eligibility.eligible', [$event, $registration->eventParticipant]))
            ->assertOk()
            ->assertJsonPath('data.status', ParticipantStatus::Donating->value);
        $this->getJson(route('events.operations.waiting.snapshot', $event))
            ->assertOk()
            ->assertJsonPath('queue.positions.0.position.value', ParticipantStatus::Donating->value);
        $this->getJson(route('events.operations.data', [$event, ParticipantStatus::Donating->value]))
            ->assertOk()
            ->assertJsonPath('data.0.id', $registration->eventParticipant->id)
            ->assertJsonPath('meta.donation_capacity.male.active', 1)
            ->assertJsonPath('meta.donation_capacity.male.available', 3)
            ->assertJsonPath('meta.donation_capacity.female.active', 0);

        $this->postJson(route('events.operations.completed.store', [$event, $registration->eventParticipant]))
            ->assertOk()
            ->assertJsonPath('data.status', ParticipantStatus::Finished->value);
        $this->getJson(route('events.operations.data', [$event, ParticipantStatus::Finished->value]))
            ->assertOk()
            ->assertJsonPath('data.0.id', $registration->eventParticipant->id);
        $this->getJson(route('dashboard.data'))
            ->assertOk()
            ->assertJsonPath('metrics.finished', 1)
            ->assertJsonPath('donation_capacity.total.active', 0)
            ->assertJsonPath('donation_capacity.male.available', 4)
            ->assertJsonPath('donation_capacity.female.available', 4);
        $this->getJson(route('events.monitor.data', $event))
            ->assertOk()
            ->assertJsonStructure(['event', 'queues'])
            ->assertJsonCount(0, 'queues.0.active_positions');
    }

    private function activeEvent(): Event
    {
        $event = Event::factory()->active()->create();

        ServicePost::factory()->for($event)->create([
            'type' => ServicePostType::Health,
            'behavior' => ServicePostBehavior::HealthForm,
            'sequence' => 1,
            'is_active' => true,
        ]);
        ServicePost::factory()->for($event)->create([
            'type' => ServicePostType::Screening,
            'behavior' => ServicePostBehavior::ScreeningForm,
            'sequence' => 2,
            'is_active' => true,
        ]);
        ServicePost::factory()->for($event)->create([
            'type' => ServicePostType::Donation,
            'behavior' => ServicePostBehavior::DonationForm,
            'sequence' => 3,
            'is_active' => true,
        ]);

        return $event;
    }
}
