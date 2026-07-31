<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\ParticipantStatus;
use App\Enums\QueueTicketStatus;
use App\Enums\QueueType;
use App\Enums\ServicePostType;
use App\Enums\UserRole;
use App\Events\DashboardUpdated;
use App\Events\HealthQueueUpdated;
use App\Events\ParticipantHealthCheckCompleted;
use App\Events\ParticipantMovedToEligibility;
use App\Events\QueueUpdated;
use App\Events\TVMonitorUpdated;
use App\Http\Requests\Health\SaveHealthAssessmentRequest;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\HealthAssessment;
use App\Models\Participant;
use App\Models\QueueTicket;
use App\Models\ServicePost;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
use Tests\TestCase;

class HealthAssessmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_queue_requires_authentication_and_permission(): void
    {
        $event = Event::factory()->active()->create();

        $this->get(route('events.health.index', $event))
            ->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->get(route('events.health.index', $event))
            ->assertForbidden();
    }

    public function test_waiting_ticket_can_be_called_skipped_and_recalled(): void
    {
        EventFacade::fake();
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        $ticket = $this->healthTicket($event);

        $this->actingAs($administrator)
            ->postJson(route('events.health.call', [$event, $ticket]))
            ->assertOk()
            ->assertJsonPath('data.status', QueueTicketStatus::Calling->value);

        $this->assertSame(QueueTicketStatus::Calling, $ticket->refresh()->status);
        $this->assertSame($administrator->id, $ticket->called_by);
        $this->assertNotNull($ticket->called_at);

        $this->actingAs($administrator)
            ->post(route('events.health.skip', [$event, $ticket]))
            ->assertRedirect();
        $this->assertSame(QueueTicketStatus::Skipped, $ticket->refresh()->status);

        $this->actingAs($administrator)
            ->post(route('events.health.call', [$event, $ticket]))
            ->assertRedirect();
        $this->assertSame(QueueTicketStatus::Calling, $ticket->refresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'subject_type' => QueueTicket::class,
            'subject_id' => $ticket->id,
            'action' => AuditAction::QueueTicketSkipped->value,
        ]);
        EventFacade::assertDispatchedTimes(HealthQueueUpdated::class, 3);
        EventFacade::assertDispatchedTimes(QueueUpdated::class, 3);
        EventFacade::assertDispatchedTimes(DashboardUpdated::class, 3);
        EventFacade::assertDispatchedTimes(TVMonitorUpdated::class, 3);
    }

    public function test_starting_health_service_updates_ticket_and_participant_atomically(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        $ticket = $this->healthTicket($event, QueueTicketStatus::Calling);
        EventFacade::fake();

        $this->actingAs($administrator)
            ->post(route('events.health.start', [$event, $ticket]))
            ->assertRedirect(route('events.health.assessments.edit', [$event, $ticket]));

        $this->assertSame(QueueTicketStatus::Serving, $ticket->refresh()->status);
        $this->assertNotNull($ticket->served_at);
        $this->assertSame(
            ParticipantStatus::HealthInProgress,
            $ticket->eventParticipant->refresh()->status,
        );
        EventFacade::assertDispatched(QueueUpdated::class);
        EventFacade::assertDispatched(DashboardUpdated::class);
        EventFacade::assertDispatched(TVMonitorUpdated::class);
    }

    public function test_serving_ticket_can_complete_assessment_and_move_to_screening(): void
    {
        EventFacade::fake();
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        $ticket = $this->healthTicket($event, QueueTicketStatus::Serving);
        $screeningPost = ServicePost::factory()->for($event)->create([
            'code' => 'screening',
            'name' => 'Screening Donor',
            'type' => ServicePostType::Screening,
            'sequence' => 2,
            'is_active' => true,
        ]);

        $this->actingAs($administrator)
            ->postJson(route('events.health.assessments.store', [$event, $ticket]), $this->assessmentPayload())
            ->assertOk()
            ->assertJsonPath('redirect_url', route('events.health.index', $event));

        $assessment = HealthAssessment::query()->firstOrFail();
        $eventParticipant = $ticket->eventParticipant->refresh();

        $this->assertSame('120/80', $assessment->blood_pressure);
        $this->assertSame('105.50', $assessment->blood_sugar);
        $this->assertSame($administrator->id, $assessment->created_by);
        $this->assertSame(QueueTicketStatus::Finished, $ticket->refresh()->status);
        $this->assertNotNull($ticket->finished_at);
        $this->assertSame(ParticipantStatus::WaitingScreening, $eventParticipant->status);
        $this->assertSame($screeningPost->id, $eventParticipant->current_service_post_id);
        $this->assertDatabaseHas('audit_logs', [
            'subject_type' => HealthAssessment::class,
            'subject_id' => $assessment->id,
            'action' => AuditAction::HealthAssessmentCompleted->value,
        ]);
        EventFacade::assertDispatched(
            HealthQueueUpdated::class,
            fn (HealthQueueUpdated $broadcast): bool => $broadcast->queueTicketId === $ticket->id
                && $broadcast->action === AuditAction::HealthAssessmentCompleted
        );
        EventFacade::assertDispatched(ParticipantHealthCheckCompleted::class);
        EventFacade::assertDispatched(ParticipantMovedToEligibility::class);
        EventFacade::assertDispatched(QueueUpdated::class);
        EventFacade::assertDispatched(DashboardUpdated::class);
        EventFacade::assertDispatched(TVMonitorUpdated::class);
    }

    public function test_completion_requires_serving_ticket_and_active_screening_post(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        $waitingTicket = $this->healthTicket($event);

        $this->actingAs($administrator)
            ->from(route('events.health.assessments.edit', [$event, $waitingTicket]))
            ->post(route('events.health.assessments.store', [$event, $waitingTicket]), $this->assessmentPayload())
            ->assertSessionHasErrors('queue_ticket');

        $waitingTicket->status = QueueTicketStatus::Serving;
        $waitingTicket->save();
        $waitingTicket->eventParticipant()->update(['status' => ParticipantStatus::HealthInProgress]);

        $this->actingAs($administrator)
            ->from(route('events.health.assessments.edit', [$event, $waitingTicket]))
            ->post(route('events.health.assessments.store', [$event, $waitingTicket]), $this->assessmentPayload())
            ->assertSessionHasErrors('event');

        $this->assertDatabaseCount('health_assessments', 0);
        $this->assertSame(QueueTicketStatus::Serving, $waitingTicket->refresh()->status);
    }

    public function test_health_service_can_be_confirmed_without_medical_data_while_legacy_payload_validation_remains(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        $ticket = $this->healthTicket($event, QueueTicketStatus::Serving);
        ServicePost::factory()->for($event)->create([
            'type' => ServicePostType::Screening,
            'sequence' => 2,
        ]);

        $this->actingAs($administrator)
            ->post(route('events.health.assessments.store', [$event, $ticket]), [])
            ->assertRedirect(route('events.health.index', $event))
            ->assertSessionDoesntHaveErrors();

        $assessment = HealthAssessment::query()->firstOrFail();
        $this->assertNull($assessment->blood_pressure);
        $this->assertNull($assessment->blood_sugar);
        $this->assertSame(QueueTicketStatus::Finished, $ticket->refresh()->status);

        $invalidRequest = new SaveHealthAssessmentRequest;
        $validator = validator(
            ['blood_pressure' => '120'],
            $invalidRequest->rules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('blood_pressure', $validator->errors()->toArray());
    }

    public function test_finished_assessment_can_be_viewed_updated_and_is_scoped_to_event(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        $otherEvent = Event::factory()->create(['created_by' => $administrator->id]);
        $ticket = $this->healthTicket($event, QueueTicketStatus::Serving);
        ServicePost::factory()->for($event)->create([
            'type' => ServicePostType::Screening,
            'sequence' => 2,
        ]);

        $this->actingAs($administrator)
            ->post(route('events.health.assessments.store', [$event, $ticket]), $this->assessmentPayload());

        $assessment = HealthAssessment::query()->firstOrFail();

        $this->actingAs($administrator)
            ->get(route('events.health.assessments.edit', [$event, $ticket]))
            ->assertOk()
            ->assertSee('Layanan kesehatan telah dikonfirmasi')
            ->assertDontSee('name="blood_pressure"', false)
            ->assertDontSee('Data pengukuran');

        EventFacade::fake();
        $this->actingAs($administrator)
            ->patch(
                route('events.health.assessments.update', [$event, $assessment]),
                $this->assessmentPayload(['blood_pressure' => '118 / 78'])
            )
            ->assertRedirect(route('events.health.index', $event));
        $this->assertSame('118/78', $assessment->refresh()->blood_pressure);
        EventFacade::assertDispatched(QueueUpdated::class);
        EventFacade::assertDispatched(DashboardUpdated::class);
        EventFacade::assertDispatched(TVMonitorUpdated::class);

        $this->actingAs($administrator)
            ->patch(
                route('events.health.assessments.update', [$otherEvent, $assessment]),
                $this->assessmentPayload()
            )
            ->assertNotFound();
    }

    public function test_health_queue_page_and_realtime_data_include_ticket(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        $ticket = $this->healthTicket($event);

        $this->actingAs($administrator)
            ->get(route('events.health.index', $event))
            ->assertOk()
            ->assertSee('Antrean Pemeriksaan');

        $this->actingAs($administrator)
            ->getJson(route('events.health.data', $event))
            ->assertOk()
            ->assertJsonPath('data.0.id', $ticket->id)
            ->assertJsonPath('data.0.status', QueueTicketStatus::Waiting->value);
    }

    private function healthTicket(
        Event $event,
        QueueTicketStatus $status = QueueTicketStatus::Waiting,
    ): QueueTicket {
        $healthPost = ServicePost::factory()->for($event)->create([
            'code' => 'health-'.fake()->unique()->numerify('###'),
            'type' => ServicePostType::Health,
            'sequence' => 1,
            'is_active' => true,
        ]);
        $participantStatus = $status === QueueTicketStatus::Serving
            ? ParticipantStatus::HealthInProgress
            : ParticipantStatus::WaitingHealth;
        $eventParticipant = EventParticipant::factory()
            ->for($event)
            ->for(Participant::factory())
            ->create([
                'current_service_post_id' => $healthPost->id,
                'status' => $participantStatus,
                'checked_in_at' => now(),
            ]);

        return QueueTicket::factory()->create([
            'event_id' => $event->id,
            'event_participant_id' => $eventParticipant->id,
            'service_post_id' => $healthPost->id,
            'queue_type' => QueueType::General,
            'number' => 1,
            'status' => $status,
            'called_at' => in_array($status, [QueueTicketStatus::Calling, QueueTicketStatus::Serving], true) ? now() : null,
            'served_at' => $status === QueueTicketStatus::Serving ? now() : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function assessmentPayload(array $overrides = []): array
    {
        return array_merge([
            'blood_pressure' => '120 / 80',
            'blood_sugar' => '105.50',
            'cholesterol' => '190',
            'uric_acid' => '5.7',
            'notes' => 'Kondisi peserta baik.',
        ], $overrides);
    }

    private function administrator(): User
    {
        $this->seed(RolePermissionSeeder::class);

        return User::query()->role(UserRole::Administrator->value)->firstOrFail();
    }
}
