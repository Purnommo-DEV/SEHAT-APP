<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\EventStatus;
use App\Enums\ParticipantGender;
use App\Enums\ParticipantServiceType;
use App\Enums\ParticipantStatus;
use App\Enums\QueueTicketStatus;
use App\Enums\QueueType;
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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
use Tests\TestCase;

class CheckInTest extends TestCase
{
    use RefreshDatabase;

    public function test_check_in_is_available_without_authentication(): void
    {
        $event = Event::factory()->active()->create();

        $this->get(route('events.check-ins.index', $event))
            ->assertOk();

        $this->actingAs(User::factory()->create())
            ->get(route('events.check-ins.index', $event))
            ->assertOk();
    }

    public function test_active_check_in_route_returns_to_dashboard_when_no_event_is_active(): void
    {
        $this->get(route('check-ins.active'))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status');
    }

    public function test_operator_can_quick_add_a_participant_with_only_a_name_and_select_it_from_registration(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        $this->healthPost($event);

        $response = $this->postJson(route('events.check-ins.participants.store', $event), [
            'name' => '  Peserta   Cepat  ',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Peserta Cepat')
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.gender', ParticipantGender::Male->value);

        $participant = Participant::query()->findOrFail($response->json('data.id'));
        $this->assertNull($participant->phone);

        $this->postJson(route('events.check-ins.store', $event), [
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
        $healthPost = $this->healthPost($event);
        $participant = Participant::factory()->create([
            'name' => 'Ratna Sehat',
            'gender' => ParticipantGender::Male,
        ]);

        $response = $this->actingAs($administrator)
            ->post(route('events.check-ins.store', $event), [
                'participant_id' => $participant->id,
                'services' => [ParticipantServiceType::HealthCheck->value],
            ]);

        $eventParticipant = EventParticipant::query()->firstOrFail();
        $queueTicket = QueueTicket::query()->firstOrFail();

        $response->assertRedirect(route('events.check-ins.show', [$event, $eventParticipant]));
        $this->assertSame(ParticipantStatus::Waiting, $eventParticipant->status);
        $this->assertSame(1, $eventParticipant->services()->count());
        $this->assertSame(1, $eventParticipant->registration_number);
        $this->assertSame('L001', $eventParticipant->formattedRegistrationNumber($event->settings));
        $this->assertSame($healthPost->id, $eventParticipant->current_service_post_id);
        $this->assertSame(QueueType::General, $queueTicket->queue_type);
        $this->assertSame(QueueTicketStatus::Waiting, $queueTicket->status);
        $this->assertSame(1, $queueTicket->number);
        $this->assertSame('L001', $queueTicket->formattedNumber());
        $this->assertDatabaseHas('audit_logs', [
            'event_id' => $event->id,
            'subject_type' => EventParticipant::class,
            'subject_id' => $eventParticipant->id,
            'action' => AuditAction::ParticipantCheckedIn->value,
        ]);
        EventFacade::assertDispatched(
            ParticipantCheckedIn::class,
            fn (ParticipantCheckedIn $broadcast): bool => $broadcast->eventParticipantId === $eventParticipant->id
                && $broadcast->queueNumber === 'L001'
                && $broadcast->registrationNumber === 'L001'
                && $broadcast->services === [ParticipantServiceType::HealthCheck->value]
        );
    }

    public function test_queue_number_increments_and_duplicate_submission_is_idempotent(): void
    {
        EventFacade::fake([ParticipantCheckedIn::class]);
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        $this->healthPost($event);
        $firstParticipant = Participant::factory()->create(['gender' => ParticipantGender::Male]);
        $secondParticipant = Participant::factory()->create(['gender' => ParticipantGender::Female]);

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
            ->assertSessionHas('status', 'Peserta sudah check-in dengan nomor registrasi L001.');

        $this->assertDatabaseCount('event_participants', 2);
        $this->assertDatabaseCount('queue_tickets', 2);
        $this->assertSame([1, 2], QueueTicket::query()->orderBy('number')->pluck('number')->all());
        EventFacade::assertDispatchedTimes(ParticipantCheckedIn::class, 2);
    }

    public function test_check_in_pages_data_and_scoped_binding_are_available(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        $otherEvent = Event::factory()->create(['created_by' => $administrator->id]);
        $this->healthPost($event);
        $participant = Participant::factory()->create(['gender' => ParticipantGender::Male]);

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
            ->assertJsonPath('data.0.formatted_number', 'L001')
            ->assertJsonPath('data.0.registration_number', 'L001')
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

        $this->getJson(route('events.check-ins.data', $event))->assertNotFound();
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

    public function test_existing_event_participant_without_initial_ticket_is_recovered_idempotently(): void
    {
        $event = Event::factory()->active()->create();
        $healthPost = $this->healthPost($event);
        $participant = Participant::factory()->create(['gender' => ParticipantGender::Male]);
        $eventParticipant = EventParticipant::factory()
            ->for($event)
            ->for($participant)
            ->create([
                'status' => ParticipantStatus::Waiting,
                'registration_number' => 20,
                'registration_number_scope' => EventParticipant::registrationNumberScopeFor(ParticipantGender::Male),
                'active_registration_number' => 20,
                'checked_in_at' => now(),
                'current_service_post_id' => $healthPost->id,
            ]);

        $this->postJson(route('events.check-ins.store', $event), [
            'participant_id' => $participant->id,
            'services' => [ParticipantServiceType::HealthCheck->value],
        ])
            ->assertOk()
            ->assertJsonPath('data.registration_number', 'L020');

        $this->postJson(route('events.check-ins.store', $event), [
            'participant_id' => $participant->id,
            'services' => [ParticipantServiceType::HealthCheck->value],
        ])->assertOk();

        $this->assertDatabaseCount('participants', 1);
        $this->assertDatabaseCount('event_participants', 1);
        $this->assertDatabaseCount('queue_tickets', 1);
        $this->assertDatabaseCount('event_participant_services', 1);
        $this->assertDatabaseHas('queue_tickets', [
            'event_participant_id' => $eventParticipant->id,
            'service_post_id' => $healthPost->id,
            'status' => QueueTicketStatus::Waiting->value,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'subject_type' => EventParticipant::class,
            'subject_id' => $eventParticipant->id,
            'action' => AuditAction::ParticipantCheckedIn->value,
        ]);
    }

    public function test_registration_numbers_increment_independently_for_each_gender_in_an_event(): void
    {
        $event = Event::factory()->active()->create();
        $this->healthPost($event);
        $this->createExistingRegistration($event, ParticipantGender::Male, 20);
        $this->createExistingRegistration($event, ParticipantGender::Female, 19);

        $maleOne = Participant::factory()->create(['gender' => ParticipantGender::Male]);
        $femaleOne = Participant::factory()->create(['gender' => ParticipantGender::Female]);
        $maleTwo = Participant::factory()->create(['gender' => ParticipantGender::Male]);
        $femaleTwo = Participant::factory()->create(['gender' => ParticipantGender::Female]);

        foreach ([$maleOne, $femaleOne, $maleTwo, $femaleTwo] as $participant) {
            $this->postJson(route('events.check-ins.store', $event), [
                'participant_id' => $participant->id,
                'services' => [ParticipantServiceType::HealthCheck->value],
            ])->assertCreated();
        }

        $this->assertSame([21, 22], EventParticipant::query()
            ->whereIn('participant_id', [$maleOne->id, $maleTwo->id])
            ->orderBy('registration_number')
            ->pluck('registration_number')
            ->all());
        $this->assertSame([20, 21], EventParticipant::query()
            ->whereIn('participant_id', [$femaleOne->id, $femaleTwo->id])
            ->orderBy('registration_number')
            ->pluck('registration_number')
            ->all());
    }

    public function test_missing_registration_number_is_generated_when_a_waiting_record_is_recovered(): void
    {
        $event = Event::factory()->active()->create();
        $healthPost = $this->healthPost($event);
        $participant = Participant::factory()->create(['gender' => ParticipantGender::Female]);
        $eventParticipant = EventParticipant::factory()
            ->for($event)
            ->for($participant)
            ->create([
                'status' => ParticipantStatus::Waiting,
                'checked_in_at' => now(),
                'current_service_post_id' => $healthPost->id,
            ]);

        $this->postJson(route('events.check-ins.store', $event), [
            'participant_id' => $participant->id,
            'services' => [ParticipantServiceType::HealthCheck->value],
        ])
            ->assertOk()
            ->assertJsonPath('data.registration_number', 'P001');

        $this->assertDatabaseHas('event_participants', [
            'id' => $eventParticipant->id,
            'registration_number' => 1,
            'registration_number_scope' => EventParticipant::registrationNumberScopeFor(ParticipantGender::Female),
            'active_registration_number' => 1,
        ]);
    }

    public function test_registration_number_constraint_allows_matching_male_and_female_numbers_but_rejects_duplicates_in_one_lane(): void
    {
        $event = Event::factory()->create();
        $this->createExistingRegistration($event, ParticipantGender::Male, 1);
        $this->createExistingRegistration($event, ParticipantGender::Female, 1);

        try {
            $this->createExistingRegistration($event, ParticipantGender::Male, 1);
            $this->fail('Constraint nomor registrasi per gender seharusnya menolak duplikasi nomor aktif.');
        } catch (QueryException) {
            $this->assertDatabaseCount('event_participants', 2);
        }
    }

    private function createExistingRegistration(Event $event, ParticipantGender $gender, int $number): EventParticipant
    {
        return EventParticipant::factory()
            ->for($event)
            ->for(Participant::factory()->create(['gender' => $gender]))
            ->create([
                'status' => ParticipantStatus::Waiting,
                'registration_number' => $number,
                'registration_number_scope' => EventParticipant::registrationNumberScopeFor($gender),
                'active_registration_number' => $number,
                'checked_in_at' => now(),
            ]);
    }

    private function administrator(): User
    {
        $this->seed(RolePermissionSeeder::class);

        return User::query()->role(UserRole::Administrator->value)->firstOrFail();
    }
}
