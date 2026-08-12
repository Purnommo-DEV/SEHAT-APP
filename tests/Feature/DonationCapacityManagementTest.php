<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\ParticipantGender;
use App\Enums\ParticipantServiceType;
use App\Enums\ServicePostBehavior;
use App\Enums\ServicePostType;
use App\Events\DashboardUpdated;
use App\Events\QueueUpdated;
use App\Events\TVMonitorUpdated;
use App\Models\Event;
use App\Models\Participant;
use App\Models\ServicePost;
use App\Models\User;
use App\Services\CheckIn\CheckInService;
use App\Services\Operational\OperationalWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DonationCapacityManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_update_active_event_capacity_per_gender_with_audit_and_realtime_snapshots(): void
    {
        EventFacade::fake([QueueUpdated::class, DashboardUpdated::class, TVMonitorUpdated::class]);
        [$actor, $event] = $this->eventWithCapacityPermission();

        $this->actingAs($actor)
            ->patchJson(route('events.operations.donation-capacity.update', $event), [
                'donation_capacity_male' => 5,
                'donation_capacity_female' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('data.male.capacity', 5)
            ->assertJsonPath('data.male.available', 5)
            ->assertJsonPath('data.female.capacity', 3)
            ->assertJsonPath('data.female.available', 3)
            ->assertJsonPath('data.total.capacity', 8);

        $this->assertDatabaseHas('event_settings', [
            'event_id' => $event->id,
            'donation_capacity_male' => 5,
            'donation_capacity_female' => 3,
        ]);
        $this->getJson(route('dashboard.data'))
            ->assertOk()
            ->assertJsonPath('donation_capacity.male.capacity', 5)
            ->assertJsonPath('donation_capacity.female.capacity', 3)
            ->assertJsonPath('donation_capacity.total.capacity', 8);
        $this->getJson(route('events.monitor.data', $event))
            ->assertOk()
            ->assertJsonPath('donation_capacity.male.capacity', 5)
            ->assertJsonPath('donation_capacity.female.capacity', 3)
            ->assertJsonPath('queues.0.donation_capacity.capacity', 5)
            ->assertJsonPath('queues.1.donation_capacity.capacity', 3);
        $this->assertDatabaseHas('audit_logs', [
            'event_id' => $event->id,
            'user_id' => $actor->id,
            'action' => AuditAction::DonationCapacityUpdated->value,
            'old_values->gender' => ParticipantGender::Male->value,
            'old_values->capacity' => 4,
            'new_values->capacity' => 5,
        ]);
        EventFacade::assertDispatched(QueueUpdated::class);
        EventFacade::assertDispatched(DashboardUpdated::class);
        EventFacade::assertDispatched(TVMonitorUpdated::class);
    }

    public function test_user_without_special_permission_cannot_update_capacity(): void
    {
        $event = Event::factory()->active()->create();

        $this->actingAs(User::factory()->create())
            ->patchJson(route('events.operations.donation-capacity.update', $event), [
                'donation_capacity_male' => 5,
                'donation_capacity_female' => 3,
            ])
            ->assertForbidden();
    }

    public function test_capacity_controls_are_only_rendered_for_a_user_with_the_special_permission(): void
    {
        [$actor, $event] = $this->eventWithCapacityPermission();

        $this->actingAs($actor)
            ->get(route('events.operations.waiting.desk', $event))
            ->assertOk()
            ->assertSee('Kapasitas Donor per Gender')
            ->assertSee('name="donation_capacity_male"', false)
            ->assertSee('name="donation_capacity_female"', false)
            ->assertSee('SIMPAN KAPASITAS');

        $this->actingAs(User::factory()->create())
            ->get(route('events.operations.waiting.desk', $event))
            ->assertOk()
            ->assertDontSee('SIMPAN KAPASITAS')
            ->assertSee('izin khusus');
    }

    public function test_capacity_cannot_be_decreased_below_active_donors_of_the_same_gender(): void
    {
        [$actor, $event] = $this->eventWithCapacityPermission();
        $event->settings()->update([
            'donation_capacity_male' => 5,
            'donation_capacity_female' => 5,
        ]);

        $workflow = app(OperationalWorkflowService::class);
        foreach (range(1, 5) as $index) {
            $registration = app(CheckInService::class)->checkIn(
                $event,
                Participant::factory()->create(['gender' => ParticipantGender::Male]),
                $actor,
                [ParticipantServiceType::Donor],
            );
            $workflow->startHealthCheck($event, $registration->eventParticipant, $actor);
            $workflow->startDonation($event, $registration->eventParticipant, $actor);
        }

        $this->actingAs($actor)
            ->patchJson(route('events.operations.donation-capacity.update', $event), [
                'donation_capacity_male' => 4,
                'donation_capacity_female' => 3,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.donation_capacity_male.0', 'Kapasitas tidak dapat dikurangi karena masih ada 5 peserta laki-laki yang sedang donor.');

        $this->assertDatabaseHas('event_settings', [
            'event_id' => $event->id,
            'donation_capacity_male' => 5,
            'donation_capacity_female' => 5,
        ]);
    }

    private function eventWithCapacityPermission(): array
    {
        $actor = User::factory()->create();
        $permission = Permission::query()->firstOrCreate([
            'name' => 'event.update_donation_capacity',
            'guard_name' => 'web',
        ]);
        $actor->givePermissionTo($permission);
        $event = Event::factory()->active()->for($actor, 'creator')->create();

        ServicePost::factory()->for($event)->create([
            'type' => ServicePostType::Health,
            'behavior' => ServicePostBehavior::HealthForm,
            'sequence' => 1,
            'is_active' => true,
        ]);
        ServicePost::factory()->for($event)->create([
            'type' => ServicePostType::Donation,
            'behavior' => ServicePostBehavior::DonationForm,
            'sequence' => 2,
            'is_active' => true,
        ]);

        return [$actor, $event->fresh('settings')];
    }
}
