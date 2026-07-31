<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\EventStatus;
use App\Enums\ParticipantGender;
use App\Enums\ParticipantServiceType;
use App\Enums\ParticipantStatus;
use App\Enums\QueueTicketStatus;
use App\Enums\QueueType;
use App\Enums\RegistrationNumberFormat;
use App\Enums\ServicePostBehavior;
use App\Enums\ServicePostType;
use App\Enums\UserRole;
use App\Events\ParticipantCheckedIn;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Participant;
use App\Models\QueueTicket;
use App\Models\ServicePost;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
use Tests\TestCase;

class CheckInTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_and_user_without_permission_cannot_access_check_in(): void
    {
        $event = Event::factory()->active()->create();

        $this->get(route('events.check-ins.index', $event))
            ->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->get(route('events.check-ins.index', $event))
            ->assertForbidden();
    }

    public function test_active_check_in_route_returns_to_dashboard_when_no_event_is_active(): void
    {
        $administrator = $this->administrator();

        $this->actingAs($administrator)
            ->get(route('check-ins.active'))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status');
    }

    public function test_operator_can_quick_add_a_participant_with_only_a_name_and_select_it_from_registration(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        $this->healthPost($event);

        $response = $this->actingAs($administrator)
            ->postJson(route('events.check-ins.participants.store', $event), [
                'name' => '  Peserta   Cepat  ',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Peserta Cepat')
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.gender', ParticipantGender::Male->value);

        $participant = Participant::query()->findOrFail($response->json('data.id'));
        $this->assertNull($participant->phone);

        $this->actingAs($administrator)
            ->postJson(route('events.check-ins.store', $event), [
                'participant_id' => $participant->id,
                'services' => [ParticipantServiceType::HealthCheck->value],
            ])
            ->assertCreated()
            ->assertJsonPath('data.participant.id', $participant->id)
            ->assertJsonPath('data.participant.gender', ParticipantGender::Male->value);

        $this->assertDatabaseHas('audit_logs', [
            'subject_type' => Participant::class,
            'subject_id' => $participant->id,
            'action' => AuditAction::ParticipantCreated->value,
        ]);
    }

    public function test_selected_health_service_requires_an_active_health_post(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        $participant = Participant::factory()->create();

        $this->actingAs($administrator)
            ->from(route('events.check-ins.index', $event))
            ->post(route('events.check-ins.store', $event), [
                'participant_id' => $participant->id,
                'services' => [ParticipantServiceType::HealthCheck->value],
            ])
            ->assertRedirect(route('events.check-ins.index', $event))
            ->assertSessionHasErrors('services');

        $this->assertDatabaseCount('event_participants', 0);
        $this->assertDatabaseCount('queue_tickets', 0);
    }

    public function test_health_only_registration_remains_available_when_donor_posts_are_missing(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        $this->healthPost($event);

        $response = $this->actingAs($administrator)
            ->get(route('events.check-ins.index', $event))
            ->assertOk()
            ->assertSee('Sebagian layanan belum tersedia')
            ->assertSee('Registrasi tetap dapat dilakukan untuk layanan yang sudah siap.')
            ->assertDontSee('Check-In belum dapat digunakan');
        $content = $response->getContent();

        $this->assertStringContainsString(
            ':disabled="! eventActive || ! serviceAvailable(\'donor\')"',
            $content,
        );
        $this->assertStringContainsString(
            ':disabled="! eventActive || ! serviceAvailable(\'health_check\')"',
            $content,
        );
        $this->assertStringContainsString(
            ':disabled="! selected || submitting || ! eventActive || ! hasAvailableService || selectedServices.length === 0"',
            $content,
        );
    }

    public function test_donor_registration_requires_screening_and_donation_posts(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        $participant = Participant::factory()->create();
        ServicePost::factory()->for($event)->create([
            'behavior' => ServicePostBehavior::ScreeningForm,
            'sequence' => 2,
            'is_active' => true,
        ]);
        $this->healthPost($event);

        $this->actingAs($administrator)
            ->from(route('events.check-ins.index', $event))
            ->post(route('events.check-ins.store', $event), [
                'participant_id' => $participant->id,
                'services' => [ParticipantServiceType::Donor->value],
            ])
            ->assertRedirect(route('events.check-ins.index', $event))
            ->assertSessionHasErrors('services');

        $this->assertDatabaseCount('event_participants', 0);
        $this->assertDatabaseCount('queue_tickets', 0);
    }

    public function test_check_in_creates_workflow_ticket_audit_and_realtime_event(): void
    {
        EventFacade::fake([ParticipantCheckedIn::class]);
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        $this->useUniformRegistrationNumbers($event);
        $healthPost = $this->healthPost($event);
        $participant = Participant::factory()->create(['name' => 'Ratna Sehat']);

        $response = $this->actingAs($administrator)
            ->post(route('events.check-ins.store', $event), [
                'participant_id' => $participant->id,
                'services' => [ParticipantServiceType::HealthCheck->value],
            ]);

        $eventParticipant = EventParticipant::query()->firstOrFail();
        $queueTicket = QueueTicket::query()->firstOrFail();

        $response->assertRedirect(route('events.check-ins.show', [$event, $eventParticipant]));
        $this->assertSame(ParticipantStatus::WaitingHealth, $eventParticipant->status);
        $this->assertSame(1, $eventParticipant->services()->count());
        $this->assertSame(1, $eventParticipant->registration_number);
        $this->assertSame('R001', $eventParticipant->formattedRegistrationNumber($event->settings));
        $this->assertSame($healthPost->id, $eventParticipant->current_service_post_id);
        $this->assertSame(QueueType::General, $queueTicket->queue_type);
        $this->assertSame(QueueTicketStatus::Waiting, $queueTicket->status);
        $this->assertSame(1, $queueTicket->number);
        $this->assertSame('001', $queueTicket->formattedNumber());
        $this->assertDatabaseHas('audit_logs', [
            'event_id' => $event->id,
            'subject_type' => EventParticipant::class,
            'subject_id' => $eventParticipant->id,
            'action' => AuditAction::ParticipantCheckedIn->value,
        ]);
        EventFacade::assertDispatched(
            ParticipantCheckedIn::class,
            fn (ParticipantCheckedIn $broadcast): bool => $broadcast->eventParticipantId === $eventParticipant->id
                && $broadcast->queueNumber === '001'
                && $broadcast->registrationNumber === 'R001'
                && $broadcast->services === [ParticipantServiceType::HealthCheck->value]
        );
    }

    public function test_queue_number_increments_and_duplicate_submission_is_idempotent(): void
    {
        EventFacade::fake([ParticipantCheckedIn::class]);
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        $this->useUniformRegistrationNumbers($event);
        $this->healthPost($event);
        [$firstParticipant, $secondParticipant] = Participant::factory()->count(2)->create();

        $this->actingAs($administrator)
            ->post(route('events.check-ins.store', $event), [
                'participant_id' => $firstParticipant->id,
                'services' => [ParticipantServiceType::HealthCheck->value],
            ])
            ->assertRedirect();
        $this->actingAs($administrator)
            ->post(route('events.check-ins.store', $event), [
                'participant_id' => $secondParticipant->id,
                'services' => [ParticipantServiceType::HealthCheck->value],
            ])
            ->assertRedirect();
        $this->actingAs($administrator)
            ->post(route('events.check-ins.store', $event), [
                'participant_id' => $firstParticipant->id,
                'services' => [ParticipantServiceType::HealthCheck->value],
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Peserta sudah check-in dengan nomor registrasi R001.');

        $this->assertDatabaseCount('event_participants', 2);
        $this->assertDatabaseCount('queue_tickets', 2);
        $this->assertSame([1, 2], QueueTicket::query()->orderBy('number')->pluck('number')->all());
        EventFacade::assertDispatchedTimes(ParticipantCheckedIn::class, 2);
    }

    public function test_check_in_pages_data_and_scoped_binding_are_available(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        $this->useUniformRegistrationNumbers($event);
        $otherEvent = Event::factory()->create(['created_by' => $administrator->id]);
        $this->healthPost($event);
        $participant = Participant::factory()->create();

        $this->actingAs($administrator)
            ->post(route('events.check-ins.store', $event), [
                'participant_id' => $participant->id,
                'services' => [ParticipantServiceType::HealthCheck->value],
            ]);

        $eventParticipant = EventParticipant::query()->firstOrFail();

        $this->actingAs($administrator)
            ->get(route('events.check-ins.index', $event))
            ->assertOk()
            ->assertSee('Registrasi Ulang')
            ->assertSee('Tambah peserta cepat')
            ->assertSee('+ Tambah Peserta Baru');

        $this->actingAs($administrator)
            ->getJson(route('events.check-ins.data', $event))
            ->assertOk()
            ->assertJsonPath('data.0.formatted_number', '001')
            ->assertJsonPath('data.0.registration_number', 'R001')
            ->assertJsonPath('data.0.participant.id', $participant->id)
            ->assertJsonPath('data.0.participant.gender', $participant->gender->value)
            ->assertJsonPath('data.0.participant.gender_label', $participant->gender->label())
            ->assertJsonPath('meta.workflow.event_active', true)
            ->assertJsonPath('meta.workflow.available_services.donor', false)
            ->assertJsonPath('meta.workflow.available_services.health_check', true);

        $this->actingAs($administrator)
            ->get(route('events.check-ins.show', [$event, $eventParticipant]))
            ->assertOk()
            ->assertSee('001');

        $this->actingAs($administrator)
            ->get(route('events.check-ins.show', [$otherEvent, $eventParticipant]))
            ->assertNotFound();
    }

    public function test_realtime_data_exposes_current_service_availability_and_event_lifecycle(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        $this->healthPost($event);

        $this->actingAs($administrator)
            ->getJson(route('events.check-ins.data', $event))
            ->assertOk()
            ->assertJsonPath('meta.workflow.event_active', true)
            ->assertJsonPath('meta.workflow.has_available_service', true)
            ->assertJsonPath('meta.workflow.available_services.donor', false)
            ->assertJsonPath('meta.workflow.available_services.health_check', true);

        ServicePost::factory()->for($event)->create([
            'behavior' => ServicePostBehavior::ScreeningForm,
            'sequence' => 2,
            'is_active' => true,
        ]);
        ServicePost::factory()->for($event)->create([
            'behavior' => ServicePostBehavior::DonationForm,
            'sequence' => 3,
            'is_active' => true,
        ]);

        $this->actingAs($administrator)
            ->getJson(route('events.check-ins.data', $event))
            ->assertOk()
            ->assertJsonPath('meta.workflow.available_services.donor', true)
            ->assertJsonPath('meta.workflow.available_services.health_check', true)
            ->assertJsonPath('meta.workflow.errors', []);

        $event->forceFill([
            'status' => EventStatus::Completed,
            'active_marker' => null,
        ])->save();

        $this->actingAs($administrator)
            ->getJson(route('events.check-ins.data', $event))
            ->assertOk()
            ->assertJsonPath('meta.workflow.event_active', false)
            ->assertJsonPath('meta.workflow.event_status', 'completed')
            ->assertJsonPath('meta.workflow.event_status_label', 'Selesai');
    }

    public function test_service_post_queue_digit_setting_is_used_by_ticket_formatter(): void
    {
        $event = Event::factory()->create();
        $eventParticipant = EventParticipant::factory()->for($event)->create();
        $post = ServicePost::factory()->for($event)->create([
            'queue_number_digits' => 4,
        ]);
        $ticket = QueueTicket::factory()->create([
            'event_id' => $event->id,
            'event_participant_id' => $eventParticipant->id,
            'service_post_id' => $post->id,
            'number' => 12,
        ]);

        $this->assertSame('0012', $ticket->formattedNumber());
    }

    private function healthPost(Event $event): ServicePost
    {
        return ServicePost::factory()->for($event)->create([
            'code' => 'health',
            'name' => 'Pemeriksaan Kesehatan',
            'type' => ServicePostType::Custom,
            'behavior' => ServicePostBehavior::HealthForm,
            'sequence' => 1,
            'is_active' => true,
        ]);
    }

    private function useUniformRegistrationNumbers(Event $event): void
    {
        $event->settings()->update([
            'registration_number_format' => RegistrationNumberFormat::Uniform,
        ]);
        $event->unsetRelation('settings');
    }

    private function administrator(): User
    {
        $this->seed(RolePermissionSeeder::class);

        return User::query()->role(UserRole::Administrator->value)->firstOrFail();
    }
}
