<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\ParticipantServiceType;
use App\Enums\ParticipantStatus;
use App\Enums\QueueTicketStatus;
use App\Enums\ScreeningResult;
use App\Enums\ServicePostBehavior;
use App\Enums\ServicePostMoveDirection;
use App\Enums\UserRole;
use App\Events\ServiceQueueUpdated;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Participant;
use App\Models\QueueTicket;
use App\Models\ServicePost;
use App\Models\User;
use App\Services\CheckIn\CheckInService;
use App\Services\Event\EventService;
use App\Services\ServicePost\ServicePostService;
use App\Services\Workflow\ServiceQueueService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DynamicWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_operational_routing_uses_behavior_instead_of_post_name_or_global_sequence(): void
    {
        EventFacade::fake([ServiceQueueUpdated::class]);
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        $generic = $this->servicePost($event, 'meja-informasi', 1, ServicePostBehavior::ConfirmationOnly, 'I');
        $health = $this->servicePost($event, 'ruang-c', 2, ServicePostBehavior::HealthForm, 'H');
        $donation = $this->servicePost($event, 'ruang-b', 3, ServicePostBehavior::DonationForm, 'D');
        $screening = $this->servicePost($event, 'ruang-a', 4, ServicePostBehavior::ScreeningForm, 'K');

        $checkedIn = app(CheckInService::class)->checkIn(
            $event,
            Participant::factory()->create(),
            $administrator,
            [ParticipantServiceType::Donor, ParticipantServiceType::HealthCheck],
        );
        $participant = $checkedIn->eventParticipant;

        $this->assertSame($screening->id, $checkedIn->queueTicket->service_post_id);

        $this->complete($event, $screening, $checkedIn->queueTicket, [
            'result' => ScreeningResult::Eligible->value,
        ], $administrator);
        $this->assertCurrentPost($participant, $donation, ParticipantStatus::WaitingDonor);

        $this->complete(
            $event,
            $donation,
            $this->waitingTicket($participant, $donation),
            [],
            $administrator,
        );
        $this->assertCurrentPost($participant, $health, ParticipantStatus::WaitingHealth);

        $this->complete(
            $event,
            $health,
            $this->waitingTicket($participant, $health),
            [],
            $administrator,
        );

        $participant->refresh();
        $this->assertSame(ParticipantStatus::HealthCheckCompleted, $participant->status);
        $this->assertNull($participant->current_service_post_id);
        $this->assertDatabaseHas('health_assessments', [
            'event_participant_id' => $participant->id,
            'blood_pressure' => null,
            'notes' => null,
        ]);
        $this->assertDatabaseMissing('queue_tickets', [
            'event_participant_id' => $participant->id,
            'service_post_id' => $generic->id,
        ]);
        EventFacade::assertDispatchedTimes(ServiceQueueUpdated::class, 3);
    }

    public function test_reordering_draft_posts_changes_priority_within_the_same_behavior(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->create(['created_by' => $administrator->id]);
        $firstScreening = $this->servicePost(
            $event,
            'screening-utama',
            1,
            ServicePostBehavior::ScreeningForm,
            'K',
        );
        $priorityScreening = $this->servicePost(
            $event,
            'screening-prioritas',
            2,
            ServicePostBehavior::ScreeningForm,
            'P',
        );
        $this->servicePost($event, 'donor', 3, ServicePostBehavior::DonationForm, 'D');
        $this->servicePost($event, 'kesehatan', 4, ServicePostBehavior::HealthForm, 'H');

        app(ServicePostService::class)->move(
            event: $event,
            servicePost: $priorityScreening,
            direction: ServicePostMoveDirection::Up,
            actor: $administrator,
        );

        app(EventService::class)->activate($event->fresh(), $administrator);
        $checkedIn = app(CheckInService::class)->checkIn(
            $event->fresh(),
            Participant::factory()->create(),
            $administrator,
            [ParticipantServiceType::Donor],
        );

        $this->assertSame($priorityScreening->id, $checkedIn->queueTicket->service_post_id);
        $this->assertSame(EventStatus::Active, $event->refresh()->status);
        $this->assertSame(2, $firstScreening->refresh()->sequence);
        $this->assertSame(1, $priorityScreening->refresh()->sequence);
    }

    public function test_inactive_required_behavior_prevents_event_activation(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->create(['created_by' => $administrator->id]);
        $this->servicePost($event, 'screening', 1, ServicePostBehavior::ScreeningForm, 'K');
        $this->servicePost($event, 'donor', 2, ServicePostBehavior::DonationForm, 'D');
        $health = $this->servicePost($event, 'kesehatan', 3, ServicePostBehavior::HealthForm, 'H');
        $health->update(['is_active' => false]);

        try {
            app(EventService::class)->activate($event, $administrator);
            $this->fail('Event dengan workflow tidak lengkap seharusnya tidak dapat diaktifkan.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('event', $exception->errors());
        }

        $this->assertSame(EventStatus::Draft, $event->refresh()->status);
    }

    private function servicePost(
        Event $event,
        string $code,
        int $sequence,
        ServicePostBehavior $behavior,
        string $prefix,
    ): ServicePost {
        return ServicePost::factory()->for($event)->create([
            'code' => $code,
            'name' => str($code)->replace('-', ' ')->title()->toString(),
            'behavior' => $behavior,
            'queue_prefix' => $prefix,
            'sequence' => $sequence,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function complete(
        Event $event,
        ServicePost $servicePost,
        QueueTicket $ticket,
        array $attributes,
        User $actor,
    ): void {
        app(ServiceQueueService::class)->complete($event, $servicePost, $ticket, $attributes, $actor);
    }

    private function waitingTicket(EventParticipant $participant, ServicePost $servicePost): QueueTicket
    {
        return QueueTicket::query()
            ->where('event_participant_id', $participant->id)
            ->where('service_post_id', $servicePost->id)
            ->where('status', QueueTicketStatus::Waiting->value)
            ->firstOrFail();
    }

    private function assertCurrentPost(
        EventParticipant $participant,
        ServicePost $servicePost,
        ParticipantStatus $status,
    ): void {
        $participant->refresh();

        $this->assertSame($status, $participant->status);
        $this->assertSame($servicePost->id, $participant->current_service_post_id);
    }

    private function administrator(): User
    {
        $this->seed(RolePermissionSeeder::class);

        return User::query()->role(UserRole::Administrator->value)->firstOrFail();
    }
}
