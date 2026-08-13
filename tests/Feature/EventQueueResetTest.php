<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\DonorNumberMode;
use App\Enums\ParticipantGender;
use App\Enums\ParticipantServiceType;
use App\Enums\ParticipantStatus;
use App\Enums\QueueType;
use App\Enums\ServicePostBehavior;
use App\Enums\ServicePostType;
use App\Enums\UserRole;
use App\Events\DashboardUpdated;
use App\Events\QueueUpdated;
use App\Events\TVMonitorUpdated;
use App\Models\DonorScreening;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\HealthAssessment;
use App\Models\Participant;
use App\Models\QueueTicket;
use App\Models\ServicePost;
use App\Models\ServicePostSubmission;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\CheckIn\CheckInService;
use App\Services\Event\EventQueueResetService;
use App\Services\Operational\OperationalWorkflowService;
use App\Services\Realtime\WorkflowRealtimePublisher;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EventQueueResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_reset_removes_only_event_operational_data_preserves_masters_and_broadcasts_snapshots(): void
    {
        EventFacade::fake([QueueUpdated::class, DashboardUpdated::class, TVMonitorUpdated::class]);
        [$actor, $event, $healthPost] = $this->resetContext();
        $event->settings()->update(['donor_number_mode' => DonorNumberMode::GenderSeparated]);
        $participant = Participant::factory()->create(['gender' => ParticipantGender::Male]);
        $registration = app(CheckInService::class)->checkIn(
            $event,
            $participant,
            $actor,
            [ParticipantServiceType::Donor],
        );
        $workflow = app(OperationalWorkflowService::class);
        $workflow->startEligibility($event, $registration->eventParticipant, $actor);
        $workflow->markEligible($event, $registration->eventParticipant, $actor);
        $workflow->startDonation($event, $registration->eventParticipant, $actor);

        HealthAssessment::factory()->create([
            'event_id' => $event->id,
            'event_participant_id' => $registration->eventParticipant->id,
            'service_post_id' => $healthPost->id,
            'created_by' => $actor->id,
        ]);
        DonorScreening::factory()->create([
            'event_id' => $event->id,
            'event_participant_id' => $registration->eventParticipant->id,
            'service_post_id' => $healthPost->id,
            'screened_by' => $actor->id,
        ]);
        ServicePostSubmission::query()->create([
            'event_id' => $event->id,
            'event_participant_id' => $registration->eventParticipant->id,
            'service_post_id' => $healthPost->id,
            'payload' => ['source' => 'reset-test'],
            'completed_by' => $actor->id,
            'completed_at' => now(),
        ]);

        $otherEvent = Event::factory()->create();
        $otherEventParticipant = EventParticipant::factory()->for($otherEvent)->create();

        $this->actingAs($actor)
            ->postJson(route('events.queue-reset.store', $event), ['confirmation' => 'RESET'])
            ->assertOk()
            ->assertJsonPath('message', 'Antrean event berhasil direset.')
            ->assertJsonPath('data.event_participants', 1)
            ->assertJsonPath('data.queue_tickets', 2)
            ->assertJsonPath('data.active_donors', 1);

        $this->assertDatabaseCount('event_participants', 1);
        $this->assertDatabaseHas('event_participants', ['id' => $otherEventParticipant->id]);
        $this->assertDatabaseCount('queue_tickets', 0);
        $this->assertDatabaseCount('event_participant_services', 0);
        $this->assertDatabaseCount('event_participant_status_histories', 0);
        $this->assertDatabaseCount('health_assessments', 0);
        $this->assertDatabaseCount('donor_screenings', 0);
        $this->assertDatabaseCount('service_post_submissions', 0);
        $this->assertDatabaseHas('participants', ['id' => $participant->id]);
        $this->assertDatabaseHas('events', ['id' => $event->id]);
        $this->assertDatabaseHas('event_settings', [
            'event_id' => $event->id,
            'donation_capacity_male' => 4,
            'donation_capacity_female' => 4,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event_id' => $event->id,
            'user_id' => $actor->id,
            'action' => AuditAction::QueueReset->value,
            'old_values->active_donors' => 1,
            'new_values->queue_tickets_deleted' => 2,
            'new_values->event_participants_deleted' => 1,
            'new_values->status_histories_deleted' => 4,
            'new_values->active_donors_at_reset' => 1,
        ]);

        $this->getJson(route('dashboard.data'))
            ->assertOk()
            ->assertJsonPath('metrics.checked_in', 0)
            ->assertJsonPath('metrics.donating', 0);
        $this->getJson(route('events.monitor.data', $event))
            ->assertOk()
            ->assertJsonPath('queues.0.current', null)
            ->assertJsonPath('queues.1.current', null);
        EventFacade::assertDispatched(QueueUpdated::class);
        EventFacade::assertDispatched(DashboardUpdated::class);
        EventFacade::assertDispatched(TVMonitorUpdated::class);

        $nextRegistration = app(CheckInService::class)->checkIn(
            $event->fresh(),
            $participant,
            $actor,
            [ParticipantServiceType::Donor],
        );
        $this->assertSame(1, $nextRegistration->eventParticipant->registration_number);
        $workflow->startEligibility($event->fresh(), $nextRegistration->eventParticipant, $actor);
        $workflow->markEligible($event->fresh(), $nextRegistration->eventParticipant, $actor);
        $workflow->startDonation($event->fresh(), $nextRegistration->eventParticipant, $actor);
        $donorTicket = QueueTicket::query()
            ->where('event_participant_id', $nextRegistration->eventParticipant->id)
            ->where('queue_type', QueueType::MaleDonor->value)
            ->firstOrFail();
        $this->assertSame(1, $donorTicket->number);
    }

    public function test_reset_requires_special_permission_and_the_exact_confirmation_text(): void
    {
        $event = Event::factory()->active()->create();
        $actor = User::factory()->create();

        $this->actingAs($actor)
            ->postJson(route('events.queue-reset.store', $event), ['confirmation' => 'RESET'])
            ->assertForbidden();

        $permission = Permission::query()->firstOrCreate([
            'name' => 'event.reset_queue',
            'guard_name' => 'web',
        ]);
        $actor->givePermissionTo($permission);

        $this->actingAs($actor)
            ->postJson(route('events.queue-reset.store', $event), ['confirmation' => 'reset'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmation');
    }

    public function test_reset_is_idempotent_for_an_empty_event_and_still_audits_the_administrative_action(): void
    {
        [$actor, $event] = $this->resetContext();

        $this->actingAs($actor)
            ->postJson(route('events.queue-reset.store', $event), ['confirmation' => 'RESET'])
            ->assertOk()
            ->assertJsonPath('message', 'Antrean event sudah kosong.')
            ->assertJsonPath('data.is_empty', true);

        $this->assertDatabaseHas('audit_logs', [
            'event_id' => $event->id,
            'action' => AuditAction::QueueReset->value,
            'new_values->queue_tickets_deleted' => 0,
        ]);
    }

    public function test_transaction_rolls_back_when_the_immutable_audit_record_cannot_be_created(): void
    {
        [$actor, $event, $healthPost] = $this->resetContext();
        $eventParticipant = EventParticipant::factory()->for($event)->create([
            'status' => ParticipantStatus::Waiting,
        ]);
        QueueTicket::factory()->create([
            'event_id' => $event->id,
            'event_participant_id' => $eventParticipant->id,
            'service_post_id' => $healthPost->id,
        ]);

        $auditLogger = Mockery::mock(AuditLogger::class);
        $auditLogger->shouldReceive('record')->once()->andThrow(new RuntimeException('audit persistence failed'));
        $service = new EventQueueResetService(
            app(DatabaseManager::class),
            $auditLogger,
            app(WorkflowRealtimePublisher::class),
        );

        try {
            $service->reset($event, $actor);
            $this->fail('Reset harus dibatalkan ketika audit tidak dapat disimpan.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit persistence failed', $exception->getMessage());
        }

        $this->assertDatabaseHas('event_participants', ['id' => $eventParticipant->id]);
        $this->assertDatabaseHas('queue_tickets', ['event_participant_id' => $eventParticipant->id]);
    }

    public function test_danger_zone_is_visible_only_to_an_authorized_event_administrator(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $administrator = User::query()->role(UserRole::Administrator->value)->firstOrFail();
        $event = Event::factory()->for($administrator, 'creator')->create();

        $this->actingAs($administrator)
            ->get(route('events.settings.edit', $event))
            ->assertOk()
            ->assertSee('Danger Zone')
            ->assertSee('Reset Antrean Event')
            ->assertSee('Ketik <span class="font-mono text-rose-700">RESET</span>', false);

        $this->actingAs(User::factory()->create())
            ->get(route('events.settings.edit', $event))
            ->assertForbidden();
    }

    /**
     * @return array{User, Event, ServicePost}
     */
    private function resetContext(): array
    {
        $actor = User::factory()->create();
        $permission = Permission::query()->firstOrCreate([
            'name' => 'event.reset_queue',
            'guard_name' => 'web',
        ]);
        $actor->givePermissionTo($permission);
        $event = Event::factory()->active()->for($actor, 'creator')->create();
        $healthPost = ServicePost::factory()->for($event)->create([
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

        return [$actor, $event->fresh('settings'), $healthPost];
    }
}
