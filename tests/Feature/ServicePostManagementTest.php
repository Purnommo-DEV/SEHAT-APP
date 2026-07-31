<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\ParticipantServiceStatus;
use App\Enums\ParticipantServiceType;
use App\Enums\ParticipantStatus;
use App\Enums\QueueTicketStatus;
use App\Enums\ServicePostBehavior;
use App\Enums\ServicePostType;
use App\Enums\UserRole;
use App\Events\ServicePostUpdated;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventParticipantService;
use App\Models\Participant;
use App\Models\QueueTicket;
use App\Models\ServicePost;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
use Tests\TestCase;

class ServicePostManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_and_user_without_permission_cannot_manage_service_posts(): void
    {
        $event = Event::factory()->create();

        $this->get(route('events.service-posts.index', $event))
            ->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->get(route('events.service-posts.index', $event))
            ->assertForbidden();
    }

    public function test_administrator_can_create_post_with_next_sequence_audit_and_broadcast(): void
    {
        EventFacade::fake([ServicePostUpdated::class]);
        $administrator = $this->administrator();
        $event = Event::factory()->create(['created_by' => $administrator->id]);
        ServicePost::factory()->for($event)->create(['sequence' => 1]);

        $this->actingAs($administrator)
            ->post(route('events.service-posts.store', $event), $this->postPayload())
            ->assertRedirect(route('events.show', $event).'#jalur-pelayanan');

        $servicePost = ServicePost::query()->where('code', 'pemeriksaan-kesehatan')->firstOrFail();

        $this->assertSame(2, $servicePost->sequence);
        $this->assertTrue($servicePost->is_active);
        $this->assertDatabaseHas('audit_logs', [
            'event_id' => $event->id,
            'subject_type' => ServicePost::class,
            'subject_id' => $servicePost->id,
            'action' => AuditAction::ServicePostCreated->value,
        ]);
        EventFacade::assertDispatched(
            ServicePostUpdated::class,
            fn (ServicePostUpdated $broadcast): bool => $broadcast->eventId === $event->id
                && $broadcast->servicePostId === $servicePost->id
                && $broadcast->action === AuditAction::ServicePostCreated
        );
    }

    public function test_administrator_can_view_service_post_pages_and_realtime_data(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->create(['created_by' => $administrator->id]);
        $servicePost = ServicePost::factory()->for($event)->create([
            'code' => 'registrasi',
            'name' => 'Pos Registrasi',
            'sequence' => 1,
        ]);

        $this->actingAs($administrator)
            ->get(route('events.service-posts.index', $event))
            ->assertOk()
            ->assertSee('Pos Registrasi');

        $this->actingAs($administrator)
            ->get(route('events.service-posts.create', $event))
            ->assertOk()
            ->assertSee('Pos baru');

        $this->actingAs($administrator)
            ->get(route('events.service-posts.edit', [$event, $servicePost]))
            ->assertOk()
            ->assertSee('Ubah Pos Registrasi');

        $this->actingAs($administrator)
            ->getJson(route('events.service-posts.data', $event))
            ->assertOk()
            ->assertJsonPath('data.0.id', $servicePost->id)
            ->assertJsonPath('data.0.sequence', 1);
    }

    public function test_post_order_can_be_moved_without_duplicate_sequences(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->create(['created_by' => $administrator->id]);
        $first = ServicePost::factory()->for($event)->create(['sequence' => 1, 'code' => 'registrasi']);
        $second = ServicePost::factory()->for($event)->create(['sequence' => 2, 'code' => 'kesehatan']);
        $third = ServicePost::factory()->for($event)->create(['sequence' => 3, 'code' => 'screening']);

        $this->actingAs($administrator)
            ->post(route('events.service-posts.move', [$event, $third]), ['direction' => 'up'])
            ->assertRedirect(route('events.show', $event).'#jalur-pelayanan');

        $this->assertDatabaseHas('service_posts', ['id' => $third->id, 'sequence' => 2]);
        $this->assertDatabaseHas('service_posts', ['id' => $second->id, 'sequence' => 3]);

        $this->actingAs($administrator)
            ->post(route('events.service-posts.move', [$event, $third]), ['direction' => 'up'])
            ->assertRedirect(route('events.show', $event).'#jalur-pelayanan');

        $this->assertDatabaseHas('service_posts', ['id' => $third->id, 'sequence' => 1]);
        $this->assertDatabaseHas('service_posts', ['id' => $first->id, 'sequence' => 2]);
        $this->assertSame(3, ServicePost::query()->where('event_id', $event->id)->distinct('sequence')->count('sequence'));
    }

    public function test_active_event_allows_toggling_but_locks_post_data_and_order(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        $servicePost = ServicePost::factory()->for($event)->create(['sequence' => 1, 'is_active' => true]);

        $this->actingAs($administrator)
            ->post(route('events.service-posts.toggle', [$event, $servicePost]))
            ->assertRedirect(route('events.show', $event).'#jalur-pelayanan');

        $this->assertDatabaseHas('service_posts', ['id' => $servicePost->id, 'is_active' => false]);
        $this->assertDatabaseHas('audit_logs', [
            'subject_id' => $servicePost->id,
            'action' => AuditAction::ServicePostDeactivated->value,
        ]);

        $this->actingAs($administrator)
            ->from(route('events.service-posts.edit', [$event, $servicePost]))
            ->put(route('events.service-posts.update', [$event, $servicePost]), $this->postPayload(['code' => 'ubah']))
            ->assertRedirect(route('events.service-posts.edit', [$event, $servicePost]))
            ->assertSessionHasErrors('event');

        $this->actingAs($administrator)
            ->from(route('events.service-posts.index', $event))
            ->post(route('events.service-posts.move', [$event, $servicePost]), ['direction' => 'down'])
            ->assertRedirect(route('events.service-posts.index', $event))
            ->assertSessionHasErrors('event');
    }

    public function test_active_event_without_check_ins_can_bootstrap_an_active_service_post_from_the_ui_flow(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);

        $this->actingAs($administrator)
            ->get(route('events.service-posts.index', $event))
            ->assertOk()
            ->assertSee('Tambah Pos Pelayanan')
            ->assertSee('Jalur Pelayanan');

        $this->actingAs($administrator)
            ->get(route('events.service-posts.create', $event))
            ->assertOk()
            ->assertSee('Cara pelayanan');

        $this->actingAs($administrator)
            ->post(route('events.service-posts.store', $event), $this->postPayload([
                'is_active' => '0',
            ]))
            ->assertRedirect(route('events.show', $event).'#jalur-pelayanan');

        $this->assertDatabaseHas('service_posts', [
            'event_id' => $event->id,
            'behavior' => ServicePostBehavior::HealthForm->value,
            'is_active' => true,
        ]);
    }

    public function test_active_event_can_repair_a_missing_health_post_after_a_participant_has_checked_in(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        $event->eventParticipants()->create([
            'participant_id' => Participant::factory()->create()->id,
            'status' => ParticipantStatus::Registered,
        ]);

        $this->actingAs($administrator)
            ->get(route('events.service-posts.create', $event))
            ->assertOk()
            ->assertSee('Lengkapi layanan SOP yang belum tersedia');

        $this->actingAs($administrator)
            ->post(route('events.service-posts.store', $event), $this->postPayload())
            ->assertRedirect(route('events.show', $event).'#jalur-pelayanan')
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('service_posts', [
            'event_id' => $event->id,
            'behavior' => ServicePostBehavior::HealthForm->value,
            'is_active' => true,
        ]);
    }

    public function test_active_event_only_allows_missing_sop_behaviors_to_be_added(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        ServicePost::factory()->for($event)->create([
            'behavior' => ServicePostBehavior::HealthForm,
            'sequence' => 1,
        ]);

        $response = $this->actingAs($administrator)
            ->get(route('events.service-posts.create', $event))
            ->assertOk()
            ->assertSee(ServicePostBehavior::ScreeningForm->label())
            ->assertSee(ServicePostBehavior::DonationForm->label());

        $this->assertStringNotContainsString(
            '<option value="'.ServicePostBehavior::HealthForm->value.'"',
            $response->getContent(),
        );

        $this->actingAs($administrator)
            ->from(route('events.service-posts.create', $event))
            ->post(route('events.service-posts.store', $event), $this->postPayload([
                'code' => 'health-duplicate',
            ]))
            ->assertRedirect(route('events.service-posts.create', $event))
            ->assertSessionHasErrors('event');
    }

    public function test_last_required_post_cannot_be_disabled_while_selected_service_is_pending(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        $screeningPost = ServicePost::factory()->for($event)->create([
            'behavior' => ServicePostBehavior::ScreeningForm,
            'sequence' => 1,
        ]);
        $donationPost = ServicePost::factory()->for($event)->create([
            'behavior' => ServicePostBehavior::DonationForm,
            'sequence' => 2,
        ]);
        $eventParticipant = EventParticipant::factory()->for($event)->create([
            'status' => ParticipantStatus::WaitingScreening,
            'current_service_post_id' => $screeningPost->id,
        ]);
        EventParticipantService::factory()->create([
            'event_id' => $event->id,
            'event_participant_id' => $eventParticipant->id,
            'service' => ParticipantServiceType::Donor,
            'status' => ParticipantServiceStatus::WaitingScreening,
        ]);

        $this->actingAs($administrator)
            ->from(route('events.show', $event))
            ->post(route('events.service-posts.toggle', [$event, $donationPost]))
            ->assertRedirect(route('events.show', $event))
            ->assertSessionHasErrors('service_post');

        $this->assertDatabaseHas('service_posts', [
            'id' => $donationPost->id,
            'is_active' => true,
        ]);
    }

    public function test_cancelled_tickets_do_not_prevent_post_deactivation(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        $servicePost = ServicePost::factory()->for($event)->create([
            'behavior' => ServicePostBehavior::ConfirmationOnly,
            'sequence' => 1,
        ]);
        $eventParticipant = EventParticipant::factory()->for($event)->create();
        QueueTicket::factory()->create([
            'event_id' => $event->id,
            'event_participant_id' => $eventParticipant->id,
            'service_post_id' => $servicePost->id,
            'status' => QueueTicketStatus::Cancelled,
            'cancelled_at' => now(),
        ]);

        $this->actingAs($administrator)
            ->post(route('events.service-posts.toggle', [$event, $servicePost]))
            ->assertRedirect(route('events.show', $event).'#jalur-pelayanan')
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('service_posts', [
            'id' => $servicePost->id,
            'is_active' => false,
        ]);
    }

    public function test_service_post_is_scoped_to_its_event_and_draft_post_can_be_updated_deleted(): void
    {
        $administrator = $this->administrator();
        $firstEvent = Event::factory()->create(['created_by' => $administrator->id]);
        $secondEvent = Event::factory()->create(['created_by' => $administrator->id]);
        $servicePost = ServicePost::factory()->for($firstEvent)->create(['sequence' => 1]);

        $this->actingAs($administrator)
            ->get(route('events.service-posts.edit', [$secondEvent, $servicePost]))
            ->assertNotFound();

        $this->actingAs($administrator)
            ->put(route('events.service-posts.update', [$firstEvent, $servicePost]), $this->postPayload([
                'code' => 'screening-donor',
                'name' => 'Pos Screening Donor',
                'is_active' => '0',
            ]))
            ->assertRedirect(route('events.show', $firstEvent).'#jalur-pelayanan');

        $this->assertDatabaseHas('service_posts', [
            'id' => $servicePost->id,
            'code' => 'screening-donor',
            'is_active' => false,
        ]);

        $this->actingAs($administrator)
            ->delete(route('events.service-posts.destroy', [$firstEvent, $servicePost]))
            ->assertRedirect(route('events.show', $firstEvent).'#jalur-pelayanan');

        $this->assertDatabaseMissing('service_posts', ['id' => $servicePost->id]);
    }

    public function test_each_event_allows_multiple_posts_with_the_same_behavior(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->create(['created_by' => $administrator->id]);
        ServicePost::factory()->for($event)->create([
            'type' => ServicePostType::Custom,
            'behavior' => ServicePostBehavior::HealthForm,
            'sequence' => 1,
        ]);

        $this->actingAs($administrator)
            ->post(route('events.service-posts.store', $event), $this->postPayload([
                'code' => 'pemeriksaan-kesehatan-2',
                'name' => 'Pos Pemeriksaan Kesehatan 2',
            ]))
            ->assertRedirect(route('events.show', $event).'#jalur-pelayanan')
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(
            2,
            ServicePost::query()
                ->where('event_id', $event->id)
                ->where('behavior', ServicePostBehavior::HealthForm->value)
                ->count()
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function postPayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'pemeriksaan-kesehatan',
            'name' => 'Pos Pemeriksaan Kesehatan',
            'description' => 'Pemeriksaan dasar peserta.',
            'behavior' => ServicePostBehavior::HealthForm->value,
            'queue_number_digits' => 3,
            'is_active' => '1',
        ], $overrides);
    }

    private function administrator(): User
    {
        $this->seed(RolePermissionSeeder::class);

        return User::query()->role(UserRole::Administrator->value)->firstOrFail();
    }
}
