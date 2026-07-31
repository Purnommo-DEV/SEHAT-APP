<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\ParticipantStatus;
use App\Enums\QueueTicketStatus;
use App\Enums\QueueType;
use App\Enums\ServicePostType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Participant;
use App\Models\QueueTicket;
use App\Models\ServicePost;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_snapshot_contains_active_event_metrics_positions_and_timeline(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create([
            'code' => 'DONOR-2026',
            'name' => 'Donor Sehat 2026',
            'created_by' => $administrator->id,
        ]);
        $post = ServicePost::factory()->for($event)->create([
            'name' => 'Pos Pemeriksaan',
            'type' => ServicePostType::Health,
            'sequence' => 1,
        ]);
        $participant = Participant::factory()->create(['name' => 'Ahmad Dashboard']);
        $eventParticipant = EventParticipant::factory()->for($event)->for($participant)->create([
            'current_service_post_id' => $post->id,
            'status' => ParticipantStatus::WaitingService,
            'checked_in_at' => now(),
        ]);
        QueueTicket::factory()->create([
            'event_id' => $event->id,
            'event_participant_id' => $eventParticipant->id,
            'service_post_id' => $post->id,
            'queue_type' => QueueType::General,
            'number' => 1,
            'status' => QueueTicketStatus::Waiting,
        ]);
        AuditLog::query()->create([
            'event_id' => $event->id,
            'user_id' => $administrator->id,
            'action' => AuditAction::EventUpdated,
            'subject_type' => Event::class,
            'subject_id' => $event->id,
            'new_values' => ['name' => $event->name],
        ]);
        AuditLog::query()->create([
            'event_id' => $event->id,
            'user_id' => $administrator->id,
            'action' => AuditAction::ParticipantCheckedIn,
            'subject_type' => EventParticipant::class,
            'subject_id' => $eventParticipant->id,
            'new_values' => ['status' => ParticipantStatus::WaitingService->value],
        ]);

        $this->actingAs($administrator)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Donor Sehat 2026')
            ->assertSee('Posisi peserta saat ini')
            ->assertSee('Ahmad Dashboard');

        $this->actingAs($administrator)
            ->getJson(route('dashboard.data'))
            ->assertOk()
            ->assertJsonPath('event.id', $event->id)
            ->assertJsonPath('metrics.total_participants', 1)
            ->assertJsonPath('metrics.checked_in', 1)
            ->assertJsonPath('metrics.waiting_service', 1)
            ->assertJsonPath('post_metrics.0.id', $post->id)
            ->assertJsonPath('post_metrics.0.waiting', 1)
            ->assertJsonPath('current_positions.0.participant_name', 'Ahmad Dashboard')
            ->assertJsonPath('activities.0.subject_name', 'Ahmad Dashboard')
            ->assertJsonCount(2, 'activities');
    }

    public function test_dashboard_data_returns_zero_snapshot_without_active_event(): void
    {
        $administrator = $this->administrator();

        $this->actingAs($administrator)
            ->getJson(route('dashboard.data'))
            ->assertOk()
            ->assertJsonPath('event', null)
            ->assertJsonPath('metrics.total_participants', 0)
            ->assertJsonPath('current_positions', [])
            ->assertJsonPath('activities', []);
    }

    public function test_dashboard_explains_when_the_active_event_has_no_active_service_post(): void
    {
        $administrator = $this->administrator();
        Event::factory()->active()->create(['created_by' => $administrator->id]);

        $this->actingAs($administrator)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Jalur pelayanan event belum memiliki pos aktif.');
    }

    private function administrator(): User
    {
        $this->seed(RolePermissionSeeder::class);

        return User::query()->role(UserRole::Administrator->value)->firstOrFail();
    }
}
