<?php

namespace Tests\Feature;

use App\Enums\DonorNumberMode;
use App\Enums\ParticipantGender;
use App\Enums\ParticipantServiceStatus;
use App\Enums\ParticipantServiceType;
use App\Enums\ParticipantStatus;
use App\Enums\PermissionName;
use App\Enums\QueueTicketStatus;
use App\Enums\QueueType;
use App\Enums\ServicePostBehavior;
use App\Enums\ServicePostType;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Participant;
use App\Models\QueueTicket;
use App\Models\ServicePost;
use App\Models\User;
use App\Services\Monitor\MonitorService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class MonitorTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitor_requires_authentication_and_monitor_permission(): void
    {
        $event = Event::factory()->active()->create();

        $this->get(route('events.monitor.show', $event))
            ->assertRedirect(route('login'));

        $dashboardUser = User::factory()->create();
        $dashboardUser->givePermissionTo(Permission::create([
            'name' => PermissionName::ViewDashboard->value,
            'guard_name' => 'web',
        ]));

        $this->actingAs($dashboardUser)
            ->get(route('events.monitor.show', $event))
            ->assertForbidden();
    }

    public function test_viewer_can_open_read_only_monitor_and_receive_queue_snapshot(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $viewer = User::factory()->create();
        $viewer->assignRole(UserRole::Viewer->value);
        $event = Event::factory()->active()->create(['created_by' => $viewer->id]);
        $event->settings()->update([
            'donor_number_mode' => DonorNumberMode::GenderSeparated,
        ]);
        $healthPost = ServicePost::factory()->for($event)->create([
            'type' => ServicePostType::Health,
            'sequence' => 1,
        ]);
        $donationPost = ServicePost::factory()->for($event)->create([
            'type' => ServicePostType::Donation,
            'sequence' => 2,
        ]);
        $generalParticipant = $this->eventParticipant(
            $event,
            $healthPost,
            ParticipantStatus::WaitingHealth,
            'Andi Umum',
            ParticipantServiceType::HealthCheck,
            ParticipantServiceStatus::WaitingHealthCheck,
            ParticipantGender::Male,
        );
        $maleParticipant = $this->eventParticipant(
            $event,
            $donationPost,
            ParticipantStatus::WaitingDonor,
            'Budi Donor',
            ParticipantServiceType::Donor,
            ParticipantServiceStatus::WaitingDonation,
            ParticipantGender::Male,
        );
        $femaleParticipant = $this->eventParticipant(
            $event,
            $donationPost,
            ParticipantStatus::WaitingDonor,
            'Siti Donor',
            ParticipantServiceType::Donor,
            ParticipantServiceStatus::WaitingDonation,
            ParticipantGender::Female,
        );

        QueueTicket::factory()->create([
            'event_id' => $event->id,
            'event_participant_id' => $generalParticipant->id,
            'service_post_id' => $healthPost->id,
            'queue_type' => QueueType::General,
            'number' => 1,
            'status' => QueueTicketStatus::Calling,
            'called_at' => now(),
        ]);
        QueueTicket::factory()->create([
            'event_id' => $event->id,
            'event_participant_id' => $maleParticipant->id,
            'service_post_id' => $donationPost->id,
            'queue_type' => QueueType::MaleDonor,
            'number' => 1,
            'status' => QueueTicketStatus::Waiting,
        ]);
        QueueTicket::factory()->create([
            'event_id' => $event->id,
            'event_participant_id' => $femaleParticipant->id,
            'service_post_id' => $donationPost->id,
            'queue_type' => QueueType::FemaleDonor,
            'number' => 1,
            'status' => QueueTicketStatus::Calling,
            'called_at' => now(),
        ]);

        $this->actingAs($viewer)
            ->get(route('monitor.active'))
            ->assertRedirect(route('events.monitor.show', $event));

        $this->actingAs($viewer)
            ->get(route('events.monitor.show', $event))
            ->assertOk()
            ->assertSee('TV MONITOR')
            ->assertSee('Andi Umum')
            ->assertSee('P001');

        $this->actingAs($viewer)
            ->getJson(route('events.monitor.data', $event))
            ->assertOk()
            ->assertJsonPath('event.id', $event->id)
            ->assertJsonPath('queues.0.current.number', '001')
            ->assertJsonPath('queues.0.current.participant_name', 'Andi Umum')
            ->assertJsonPath('queues.0.current.service_name', 'Pemeriksaan Kesehatan')
            ->assertJsonPath('queues.0.current.status_label', 'Dipanggil')
            ->assertJsonPath('queues.0.current.instruction', "Silakan menuju {$healthPost->name}")
            ->assertJsonPath('queues.0.current.participant_gender', ParticipantGender::Male->value)
            ->assertJsonPath('queues.1.waiting.0.number', 'L001')
            ->assertJsonPath('queues.1.current.number', 'P001')
            ->assertJsonPath('queues.1.current.service_name', 'Donor Darah')
            ->assertJsonPath('queues.1.current.participant_gender', ParticipantGender::Female->value)
            ->assertJsonCount(2, 'queues');
    }

    public function test_monitor_cannot_show_inactive_event(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $viewer = User::factory()->create();
        $viewer->assignRole(UserRole::Viewer->value);
        $inactiveEvent = Event::factory()->create(['created_by' => $viewer->id]);

        $this->actingAs($viewer)
            ->get(route('events.monitor.show', $inactiveEvent))
            ->assertNotFound();
    }

    public function test_monitor_shows_an_empty_state_when_the_active_event_has_no_active_posts(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $viewer = User::factory()->create();
        $viewer->assignRole(UserRole::Viewer->value);
        $event = Event::factory()->active()->create(['created_by' => $viewer->id]);

        $this->actingAs($viewer)
            ->get(route('events.monitor.show', $event))
            ->assertOk()
            ->assertSee('Belum ada pos pelayanan aktif');

        $this->actingAs($viewer)
            ->getJson(route('events.monitor.data', $event))
            ->assertOk()
            ->assertJsonPath('queues', []);
    }

    public function test_monitor_groups_active_queue_data_by_service_post_without_n_plus_one_queries(): void
    {
        $event = Event::factory()->active()->create();
        $definitions = collect([
            [
                'type' => ServicePostType::Screening,
                'behavior' => ServicePostBehavior::ScreeningForm,
                'service' => ParticipantServiceType::Donor,
                'service_status' => ParticipantServiceStatus::WaitingScreening,
                'participant_status' => ParticipantStatus::WaitingScreening,
                'queue_type' => QueueType::General,
            ],
            [
                'type' => ServicePostType::Donation,
                'behavior' => ServicePostBehavior::DonationForm,
                'service' => ParticipantServiceType::Donor,
                'service_status' => ParticipantServiceStatus::WaitingDonation,
                'participant_status' => ParticipantStatus::WaitingDonor,
                'queue_type' => QueueType::DonorGlobal,
            ],
            [
                'type' => ServicePostType::Health,
                'behavior' => ServicePostBehavior::HealthForm,
                'service' => ParticipantServiceType::HealthCheck,
                'service_status' => ParticipantServiceStatus::WaitingHealthCheck,
                'participant_status' => ParticipantStatus::WaitingHealth,
                'queue_type' => QueueType::General,
            ],
        ]);
        $posts = $definitions->map(
            fn (array $definition, int $index): ServicePost => ServicePost::factory()
                ->for($event)
                ->create([
                    'type' => $definition['type'],
                    'behavior' => $definition['behavior'],
                    'sequence' => $index + 1,
                ]),
        );

        foreach (range(0, 44) as $index) {
            $definitionIndex = $index % $definitions->count();
            $definition = $definitions[$definitionIndex];
            $post = $posts[$definitionIndex];
            $eventParticipant = $this->eventParticipant(
                $event,
                $post,
                $definition['participant_status'],
                "Peserta Monitor {$index}",
                $definition['service'],
                $definition['service_status'],
                ParticipantGender::Male,
            );

            QueueTicket::factory()->create([
                'event_id' => $event->id,
                'event_participant_id' => $eventParticipant->id,
                'service_post_id' => $post->id,
                'queue_type' => $definition['queue_type'],
                'number' => intdiv($index, $definitions->count()) + 1,
                'status' => QueueTicketStatus::Waiting,
            ]);
        }

        $queryCount = 0;
        DB::listen(function (QueryExecuted $query) use (&$queryCount): void {
            $queryCount++;
        });

        $snapshot = app(MonitorService::class)->snapshot();

        $this->assertCount(3, $snapshot['queues']);
        $this->assertSame($posts[0]->id, $snapshot['queues'][0]['id']);
        $this->assertSame(15, $snapshot['queues'][0]['waiting_count']);
        $this->assertLessThanOrEqual(8, $queryCount);
    }

    private function eventParticipant(
        Event $event,
        ServicePost $post,
        ParticipantStatus $status,
        string $name,
        ParticipantServiceType $service,
        ParticipantServiceStatus $serviceStatus,
        ParticipantGender $gender,
    ): EventParticipant {
        $eventParticipant = EventParticipant::factory()
            ->for($event)
            ->for(Participant::factory()->state([
                'name' => $name,
                'gender' => $gender,
            ]))
            ->create([
                'current_service_post_id' => $post->id,
                'status' => $status,
                'checked_in_at' => now(),
            ]);

        $eventParticipant->services()->create([
            'event_id' => $event->id,
            'service' => $service,
            'status' => $serviceStatus,
            'selected_at' => now(),
        ]);

        return $eventParticipant;
    }
}
