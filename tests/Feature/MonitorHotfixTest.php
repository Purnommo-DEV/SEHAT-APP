<?php

namespace Tests\Feature;

use App\Enums\ParticipantGender;
use App\Enums\ParticipantServiceType;
use App\Enums\ServicePostBehavior;
use App\Enums\ServicePostType;
use App\Models\Event;
use App\Models\Participant;
use App\Models\ServicePost;
use App\Models\User;
use App\Services\CheckIn\CheckInService;
use App\Services\Operational\OperationalWorkflowService;
use App\Services\Workflow\ServiceQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorHotfixTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitor_uses_the_last_called_participant_and_its_current_operational_position(): void
    {
        [$actor, $event, $healthPost] = $this->workflow();
        $checkIn = app(CheckInService::class);
        $queue = app(ServiceQueueService::class);
        $operations = app(OperationalWorkflowService::class);

        $first = $checkIn->checkIn(
            $event,
            Participant::factory()->create(['gender' => ParticipantGender::Male]),
            $actor,
            [ParticipantServiceType::Donor],
        );
        $second = $checkIn->checkIn(
            $event,
            Participant::factory()->create(['gender' => ParticipantGender::Male]),
            $actor,
            [ParticipantServiceType::HealthCheck],
        );

        $queue->call($event, $healthPost, $first->queueTicket, $actor);
        $first->queueTicket->refresh()->update(['called_at' => now()->subMinute()]);
        $operations->startEligibility($event, $first->eventParticipant, $actor);

        $this->assertMonitorCurrent(
            $event,
            'L-001',
            'Cek Kelayakan Donor',
            'Cek Kelayakan Donor',
            'Silakan menuju Cek Kelayakan Donor',
        );

        $operations->markEligible($event, $first->eventParticipant, $actor);

        $this->assertMonitorCurrent(
            $event,
            'L-001',
            'Cek Kesehatan',
            'Cek Kesehatan',
            'Silakan menuju Cek Kesehatan',
        );

        $operations->startDonation($event, $first->eventParticipant, $actor);

        $this->assertMonitorCurrent(
            $event,
            'L-001',
            'Sedang Donor',
            'Sedang Donor',
            'Silakan menuju proses donor',
        );
        $this->getJson(route('events.monitor.data', $event))
            ->assertOk()
            ->assertJsonPath('donation_capacity.male.active', 1)
            ->assertJsonPath('donation_capacity.male.capacity', 4)
            ->assertJsonPath('donation_capacity.female.active', 0)
            ->assertJsonPath('donation_capacity.female.capacity', 4);

        $queue->call($event, $healthPost, $second->queueTicket, $actor);

        $this->assertMonitorCurrent(
            $event,
            'L-002',
            'Cek Kesehatan',
            'Sedang Dipanggil',
            'Silakan menuju Cek Kesehatan',
        );

        $operations->startHealthCheck($event, $second->eventParticipant, $actor);

        $this->assertMonitorCurrent(
            $event,
            'L-002',
            'Cek Kesehatan',
            'Cek Kesehatan',
            'Silakan menuju Cek Kesehatan',
        );
    }

    public function test_monitor_and_operational_screen_render_the_simplified_persistent_ui(): void
    {
        [, $event] = $this->workflow();

        $this->get(route('events.monitor.show', $event))
            ->assertOk()
            ->assertDontSee('Sedang diproses')
            ->assertSee('aria-label="Reload Halaman"', false);

        $markup = (string) $this->get(route('events.operations.waiting.desk', $event))
            ->assertOk()
            ->assertSee('BED TERISI')
            ->assertSee('Laki-laki')
            ->assertSee('Perempuan')
            ->assertSee('aria-label="Reload Halaman"', false)
            ->getContent();
        $positionsMarkup = substr($markup, (int) strpos($markup, 'Peserta di setiap posisi'));

        $this->assertSame(3, substr_count($positionsMarkup, 'Belum ada peserta'));
        $this->assertStringNotContainsString('hasSecondaryActiveParticipant', $positionsMarkup);
        $this->assertStringContainsString('aria-label="Peserta cek kelayakan donor"', $positionsMarkup);
        $this->assertStringContainsString('aria-label="Peserta cek kesehatan"', $positionsMarkup);
        $this->assertStringContainsString('aria-label="Peserta sedang donor"', $positionsMarkup);
    }

    private function assertMonitorCurrent(
        Event $event,
        string $number,
        string $serviceName,
        string $statusLabel,
        string $instruction,
    ): void {
        $this->getJson(route('events.monitor.data', $event))
            ->assertOk()
            ->assertJsonPath('queues.0.current.number', $number)
            ->assertJsonPath('queues.0.current.service_name', $serviceName)
            ->assertJsonPath('queues.0.current.status_label', $statusLabel)
            ->assertJsonPath('queues.0.current.instruction', $instruction);
    }

    /** @return array{User, Event, ServicePost} */
    private function workflow(): array
    {
        $actor = User::factory()->create();
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
