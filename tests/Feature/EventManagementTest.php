<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\DonorNumberMode;
use App\Enums\EventStatus;
use App\Enums\ParticipantServiceStatus;
use App\Enums\ParticipantServiceType;
use App\Enums\QueueTicketStatus;
use App\Enums\RegistrationNumberFormat;
use App\Enums\ServicePostBehavior;
use App\Enums\ServicePostType;
use App\Enums\UserRole;
use App\Events\EventLifecycleUpdated;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventParticipantService;
use App\Models\QueueTicket;
use App\Models\ServicePost;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
use Tests\TestCase;

class EventManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_event_management(): void
    {
        $this->get(route('events.index'))->assertRedirect(route('login'));
    }

    public function test_user_without_event_permission_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('events.index'))
            ->assertForbidden();
    }

    public function test_administrator_can_view_event_pages_and_realtime_data_endpoint(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->create([
            'code' => 'KESEHATAN-2026',
            'name' => 'Cek Kesehatan Gratis',
            'created_by' => $administrator->id,
        ]);

        $this->actingAs($administrator)
            ->get(route('events.index'))
            ->assertOk()
            ->assertSee('Cek Kesehatan Gratis')
            ->assertSee('Donor global')
            ->assertSee('Atur nomor donor');

        $this->actingAs($administrator)
            ->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('Aktivitas event')
            ->assertSee('Kelola Pos Pelayanan')
            ->assertSee('Tambah Pos Pelayanan')
            ->assertSee('Pengaturan Nomor')
            ->assertSee('Mode nomor donor')
            ->assertSee('Global');

        $this->actingAs($administrator)
            ->get(route('events.edit', $event))
            ->assertOk()
            ->assertSee('Ubah Cek Kesehatan Gratis');

        $this->actingAs($administrator)
            ->get(route('events.settings.edit', $event))
            ->assertOk()
            ->assertSee('Nomor registrasi')
            ->assertSee('Mode nomor donor');

        $this->actingAs($administrator)
            ->getJson(route('events.data'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $event->id)
            ->assertJsonPath('data.0.status', EventStatus::Draft->value);
    }

    public function test_administrator_can_create_event_with_default_settings_and_audit_log(): void
    {
        EventFacade::fake([EventLifecycleUpdated::class]);
        $administrator = $this->administrator();

        $this->actingAs($administrator)
            ->post(route('events.store'), $this->eventPayload())
            ->assertRedirect();

        $event = Event::query()->where('code', 'DONOR-2026-01')->firstOrFail();

        $this->assertSame(EventStatus::Draft, $event->status);
        $this->assertSame('R', $event->settings->registration_queue_prefix);
        $this->assertSame(3, $event->settings->registration_queue_digits);
        $this->assertSame(3, $event->settings->general_queue_digits);
        $this->assertSame('L', $event->settings->male_donor_queue_prefix);
        $this->assertDatabaseHas('audit_logs', [
            'event_id' => $event->id,
            'user_id' => $administrator->id,
            'action' => AuditAction::EventCreated->value,
        ]);

        EventFacade::assertDispatched(
            EventLifecycleUpdated::class,
            fn (EventLifecycleUpdated $broadcast): bool => $broadcast->eventId === $event->id
                && $broadcast->action === AuditAction::EventCreated
        );
    }

    public function test_event_code_is_normalized_and_must_be_unique(): void
    {
        $administrator = $this->administrator();

        $this->actingAs($administrator)
            ->post(route('events.store'), $this->eventPayload(['code' => ' donor-2026-01 ']))
            ->assertRedirect();

        $this->assertDatabaseHas('events', ['code' => 'DONOR-2026-01']);

        $this->actingAs($administrator)
            ->from(route('events.create'))
            ->post(route('events.store'), $this->eventPayload())
            ->assertRedirect(route('events.create'))
            ->assertSessionHasErrors('code');
    }

    public function test_only_one_event_can_be_activated_and_completed_event_releases_active_context(): void
    {
        $administrator = $this->administrator();
        $firstEvent = Event::factory()->create(['created_by' => $administrator->id]);
        $secondEvent = Event::factory()->create(['created_by' => $administrator->id]);
        $this->createOperationalPosts($firstEvent);
        $this->createOperationalPosts($secondEvent);

        $this->actingAs($administrator)
            ->post(route('events.activate', $firstEvent))
            ->assertRedirect(route('events.index'));

        $this->assertDatabaseHas('events', [
            'id' => $firstEvent->id,
            'status' => EventStatus::Active->value,
            'active_marker' => 'active',
        ]);

        $this->actingAs($administrator)
            ->from(route('events.index'))
            ->post(route('events.activate', $secondEvent))
            ->assertRedirect(route('events.index'))
            ->assertSessionHasErrors('event');

        $this->actingAs($administrator)
            ->post(route('events.complete', $firstEvent))
            ->assertRedirect(route('events.index'));

        $this->assertDatabaseHas('events', [
            'id' => $firstEvent->id,
            'status' => EventStatus::Completed->value,
            'active_marker' => null,
        ]);

        $this->actingAs($administrator)
            ->post(route('events.activate', $secondEvent))
            ->assertRedirect(route('events.index'));

        $this->assertDatabaseHas('events', [
            'id' => $secondEvent->id,
            'status' => EventStatus::Active->value,
            'active_marker' => 'active',
        ]);
    }

    public function test_event_cannot_be_activated_without_complete_operational_posts(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->create(['created_by' => $administrator->id]);

        $this->actingAs($administrator)
            ->from(route('events.show', $event))
            ->post(route('events.activate', $event))
            ->assertRedirect(route('events.show', $event))
            ->assertSessionHasErrors('event');

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'status' => EventStatus::Draft->value,
            'active_marker' => null,
        ]);
    }

    public function test_active_event_cannot_be_completed_while_a_queue_ticket_is_nonterminal(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->create(['created_by' => $administrator->id]);
        $this->createOperationalPosts($event);

        $this->actingAs($administrator)
            ->post(route('events.activate', $event))
            ->assertRedirect(route('events.index'));

        $participant = EventParticipant::factory()->for($event)->create();
        QueueTicket::factory()->create([
            'event_id' => $event->id,
            'event_participant_id' => $participant->id,
            'service_post_id' => $event->servicePosts()->firstOrFail()->id,
            'status' => QueueTicketStatus::Waiting,
        ]);

        $this->actingAs($administrator)
            ->from(route('events.index'))
            ->post(route('events.complete', $event))
            ->assertRedirect(route('events.index'))
            ->assertSessionHasErrors('event');

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'status' => EventStatus::Active->value,
            'active_marker' => 'active',
            'ended_at' => null,
        ]);
    }

    public function test_active_event_cannot_be_cancelled_while_a_participant_service_is_nonterminal(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->create(['created_by' => $administrator->id]);
        $this->createOperationalPosts($event);

        $this->actingAs($administrator)
            ->post(route('events.activate', $event))
            ->assertRedirect(route('events.index'));

        $participant = EventParticipant::factory()->for($event)->create();
        EventParticipantService::factory()->create([
            'event_id' => $event->id,
            'event_participant_id' => $participant->id,
            'service' => ParticipantServiceType::HealthCheck,
            'status' => ParticipantServiceStatus::WaitingHealthCheck,
        ]);

        $this->actingAs($administrator)
            ->from(route('events.index'))
            ->post(route('events.cancel', $event))
            ->assertRedirect(route('events.index'))
            ->assertSessionHasErrors('event');

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'status' => EventStatus::Active->value,
            'active_marker' => 'active',
            'ended_at' => null,
        ]);
    }

    public function test_draft_event_cannot_be_cancelled_while_a_legacy_queue_ticket_is_nonterminal(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->create(['created_by' => $administrator->id]);
        $post = ServicePost::factory()->for($event)->create();
        $participant = EventParticipant::factory()->for($event)->create();
        QueueTicket::factory()->create([
            'event_id' => $event->id,
            'event_participant_id' => $participant->id,
            'service_post_id' => $post->id,
            'status' => QueueTicketStatus::Calling,
        ]);

        $this->actingAs($administrator)
            ->from(route('events.index'))
            ->post(route('events.cancel', $event))
            ->assertRedirect(route('events.index'))
            ->assertSessionHasErrors('event');

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'status' => EventStatus::Draft->value,
            'active_marker' => null,
            'ended_at' => null,
        ]);
    }

    public function test_active_event_with_only_terminal_operational_records_can_be_completed(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->create(['created_by' => $administrator->id]);
        $this->createOperationalPosts($event);

        $this->actingAs($administrator)
            ->post(route('events.activate', $event))
            ->assertRedirect(route('events.index'));

        $participant = EventParticipant::factory()->for($event)->create();
        EventParticipantService::factory()->create([
            'event_id' => $event->id,
            'event_participant_id' => $participant->id,
            'service' => ParticipantServiceType::Donor,
            'status' => ParticipantServiceStatus::NotEligible,
            'completed_at' => now(),
        ]);
        QueueTicket::factory()->create([
            'event_id' => $event->id,
            'event_participant_id' => $participant->id,
            'service_post_id' => $event->servicePosts()->firstOrFail()->id,
            'status' => QueueTicketStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by' => $administrator->id,
        ]);

        $this->actingAs($administrator)
            ->post(route('events.complete', $event))
            ->assertRedirect(route('events.index'))
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'status' => EventStatus::Completed->value,
            'active_marker' => null,
        ]);
    }

    public function test_settings_can_be_updated_only_while_event_is_draft(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->create(['created_by' => $administrator->id]);
        $this->createOperationalPosts($event);

        $this->actingAs($administrator)
            ->patch(route('events.settings.update', $event), [
                'registration_number_format' => RegistrationNumberFormat::Uniform->value,
                'registration_queue_prefix' => 'REG',
                'registration_male_prefix' => 'L',
                'registration_female_prefix' => 'P',
                'registration_queue_digits' => 4,
                'donor_number_mode' => DonorNumberMode::GenderSeparated->value,
                'donor_queue_prefix' => 'DNR',
                'donor_queue_digits' => 4,
                'general_queue_digits' => 4,
                'male_donor_queue_prefix' => 'PRIA',
                'female_donor_queue_prefix' => 'WNTA',
            ])
            ->assertRedirect(route('events.show', $event));

        $this->assertDatabaseHas('event_settings', [
            'event_id' => $event->id,
            'donor_number_mode' => DonorNumberMode::GenderSeparated->value,
            'registration_queue_prefix' => 'REG',
            'registration_queue_digits' => 4,
            'general_queue_digits' => 4,
            'male_donor_queue_prefix' => 'PRIA',
            'female_donor_queue_prefix' => 'WNTA',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event_id' => $event->id,
            'action' => AuditAction::EventSettingsUpdated->value,
        ]);

        $this->actingAs($administrator)
            ->post(route('events.activate', $event))
            ->assertRedirect(route('events.index'));

        $this->actingAs($administrator)
            ->from(route('events.settings.edit', $event))
            ->patch(route('events.settings.update', $event), [
                'registration_number_format' => RegistrationNumberFormat::Uniform->value,
                'registration_queue_prefix' => 'R',
                'registration_male_prefix' => 'L',
                'registration_female_prefix' => 'P',
                'registration_queue_digits' => 5,
                'donor_number_mode' => DonorNumberMode::Global->value,
                'donor_queue_prefix' => 'D',
                'donor_queue_digits' => 5,
                'general_queue_digits' => 5,
                'male_donor_queue_prefix' => 'L',
                'female_donor_queue_prefix' => 'P',
            ])
            ->assertRedirect(route('events.settings.edit', $event))
            ->assertSessionHasErrors('event');
    }

    public function test_draft_event_can_be_updated_cancelled_and_deleted(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->create(['created_by' => $administrator->id]);

        $this->actingAs($administrator)
            ->put(route('events.update', $event), $this->eventPayload([
                'code' => 'UPDATED-2026',
                'name' => 'Kegiatan yang Diperbarui',
            ]))
            ->assertRedirect(route('events.show', $event));

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'code' => 'UPDATED-2026',
            'name' => 'Kegiatan yang Diperbarui',
        ]);

        $this->actingAs($administrator)
            ->post(route('events.cancel', $event))
            ->assertRedirect(route('events.index'));

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'status' => EventStatus::Cancelled->value,
        ]);

        $draftEvent = Event::factory()->create(['created_by' => $administrator->id]);
        $this->actingAs($administrator)
            ->delete(route('events.destroy', $draftEvent))
            ->assertRedirect(route('events.index'));

        $this->assertDatabaseMissing('events', ['id' => $draftEvent->id]);
        $this->assertSame(AuditAction::EventDeleted, AuditLog::query()->latest('id')->firstOrFail()->action);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function eventPayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'DONOR-2026-01',
            'name' => 'Donor Darah Masjid Raya',
            'description' => 'Kegiatan donor darah untuk warga sekitar.',
            'location' => 'Aula Masjid Raya',
            'starts_at' => '2026-08-09 08:00:00',
            'ends_at' => '2026-08-09 13:00:00',
        ], $overrides);
    }

    private function administrator(): User
    {
        $this->seed(RolePermissionSeeder::class);

        return User::query()->role(UserRole::Administrator->value)->firstOrFail();
    }

    private function createOperationalPosts(Event $event): void
    {
        foreach ([
            [ServicePostType::Screening, ServicePostBehavior::ScreeningForm, 1, 'K'],
            [ServicePostType::Donation, ServicePostBehavior::DonationForm, 2, 'D'],
            [ServicePostType::Health, ServicePostBehavior::HealthForm, 3, 'H'],
        ] as [$type, $behavior, $sequence, $prefix]) {
            ServicePost::factory()->for($event)->create([
                'type' => $type,
                'behavior' => $behavior,
                'queue_prefix' => $prefix,
                'is_active' => true,
                'sequence' => $sequence,
            ]);
        }
    }
}
