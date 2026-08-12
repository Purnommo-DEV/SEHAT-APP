<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\ParticipantServiceType;
use App\Enums\ServicePostBehavior;
use App\Enums\ServicePostType;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Participant;
use App\Models\ServicePost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class OperationalPublicAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_operational_screens_are_available_without_a_login(): void
    {
        $event = Event::factory()->active()->create();
        $post = $this->healthPost($event);

        $this->get(route('dashboard'))->assertOk();
        $this->get(route('check-ins.active'))->assertRedirect(route('events.check-ins.index', $event));
        $this->get(route('events.check-ins.index', $event))->assertOk();
        $this->get(route('events.operations.index', $event))
            ->assertRedirect(route('events.operations.waiting.desk', $event));
        $this->get(route('events.operations.snapshot', $event))
            ->assertOk()
            ->assertJsonStructure(['stages', 'queue' => ['post', 'tickets', 'positions']]);
        $this->get(route('events.operations.waiting', $event))
            ->assertRedirect(route('events.operations.waiting.desk', $event));
        $this->get(route('events.operations.waiting.desk', $event))
            ->assertOk();
        $this->get(route('events.operations.health-check', $event))
            ->assertRedirect(route('events.operations.waiting.desk', $event));
        $this->get(route('events.operations.before-donor', $event))
            ->assertRedirect(route('events.operations.waiting.desk', $event));
        $this->get(route('events.operations.donating', $event))
            ->assertRedirect(route('events.operations.waiting.desk', $event));
        $this->get(route('events.operations.completed', $event))
            ->assertRedirect(route('events.operations.waiting.desk', $event));
        $this->get(route('operations.stage', 'health-check'))
            ->assertRedirect(route('events.operations.waiting.desk', $event));
        $this->get(route('queues.active'))->assertRedirect(route('events.operations.waiting.desk', $event));
        $this->get(route('events.service-queues.index', [$event, $post]))
            ->assertRedirect(route('events.operations.waiting.desk', $event));
        $this->get(route('monitor.active'))->assertRedirect(route('events.monitor.show', $event));
        $this->get(route('events.monitor.show', $event))->assertOk();
    }

    public function test_guest_registration_is_audited_and_scoped_to_the_active_event(): void
    {
        $event = Event::factory()->active()->create();
        $this->healthPost($event);
        $participant = Participant::factory()->create();

        $this->postJson(route('events.check-ins.store', $event), [
            'participant_id' => $participant->id,
            'services' => [ParticipantServiceType::HealthCheck->value],
        ])->assertCreated();

        $registration = EventParticipant::query()->firstOrFail();
        $this->assertDatabaseHas('audit_logs', [
            'event_id' => $event->id,
            'subject_id' => $registration->id,
            'action' => AuditAction::ParticipantCheckedIn->value,
            'user_id' => null,
        ]);

        $otherEvent = Event::factory()->create();
        $otherParticipant = EventParticipant::factory()->for($otherEvent)->create();

        $this->postJson(route('events.operations.health-check.start', [$event, $otherParticipant]))
            ->assertNotFound();
    }

    public function test_public_operational_routes_keep_web_csrf_and_rate_limit_middleware(): void
    {
        foreach ([
            'events.check-ins.store',
            'events.operations.health-check.start',
            'events.service-queues.call',
        ] as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route);
            $this->assertContains('web', $route->gatherMiddleware());
            $this->assertContains('throttle:operational', $route->gatherMiddleware());
            $this->assertContains('operational.event', $route->gatherMiddleware());
        }
    }

    private function healthPost(Event $event): ServicePost
    {
        return ServicePost::factory()->for($event)->create([
            'type' => ServicePostType::Health,
            'behavior' => ServicePostBehavior::HealthForm,
            'is_active' => true,
            'sequence' => 1,
        ]);
    }
}
