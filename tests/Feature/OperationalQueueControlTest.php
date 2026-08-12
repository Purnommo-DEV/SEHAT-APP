<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\ParticipantGender;
use App\Enums\ParticipantStatus;
use App\Enums\QueueTicketStatus;
use App\Enums\QueueType;
use App\Enums\ServicePostBehavior;
use App\Enums\ServicePostType;
use App\Events\ServiceQueueUpdated;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Participant;
use App\Models\QueueTicket;
use App\Models\ServicePost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
use Tests\TestCase;

class OperationalQueueControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_operational_areas_are_separate_and_queue_controls_exist_only_on_the_waiting_desk(): void
    {
        [$event, $post] = $this->activeEventWithControlPost();
        $this->queueTicket($event, $post, ParticipantGender::Male, 1);
        $this->queueTicket($event, $post, ParticipantGender::Female, 2);

        $this->get(route('events.operations.index', $event))
            ->assertRedirect(route('events.operations.waiting', $event));

        $this->get(route('events.operations.waiting', $event))
            ->assertOk()
            ->assertSee('Area Tunggu')
            ->assertSee('BUKA SCREEN PETUGAS')
            ->assertSee('CEK KESEHATAN')
            ->assertDontSee('NEXT')
            ->assertDontSee('SKIP')
            ->assertDontSee('GOTO');

        $this->get(route('events.operations.waiting.desk', $event))
            ->assertOk()
            ->assertSee('Screen Petugas')
            ->assertSee('NEXT')
            ->assertSee('SKIP')
            ->assertSee('GOTO')
            ->assertSee('Cari peserta aktif')
            ->assertSee('type="search"', false)
            ->assertDontSee('name="queue_number"', false)
            ->assertSee('Laki-laki')
            ->assertSee('Perempuan');

        $this->get(route('events.operations.health-check', $event))
            ->assertOk()
            ->assertSee('Area Cek Kesehatan')
            ->assertSee('DONOR')
            ->assertSee('SELESAI')
            ->assertDontSee('NEXT');

        $this->get(route('events.operations.before-donor', $event))
            ->assertRedirect(route('events.operations.health-check', $event));

        $this->getJson(route('events.operations.snapshot', $event))
            ->assertOk()
            ->assertJsonCount(2, 'queue.tickets')
            ->assertJsonCount(2, 'queue.positions')
            ->assertJsonCount(2, 'stages.waiting');

        $this->getJson(route('events.operations.waiting.snapshot', $event))
            ->assertOk()
            ->assertJsonCount(2, 'tickets')
            ->assertJsonCount(2, 'positions');
    }

    public function test_waiting_snapshot_offers_only_active_tickets_for_the_goto_selector(): void
    {
        [$event, $post] = $this->activeEventWithControlPost();
        $active = $this->queueTicket($event, $post, ParticipantGender::Male, 1);
        $finished = $this->queueTicket($event, $post, ParticipantGender::Male, 2);
        $finished->update([
            'status' => QueueTicketStatus::Finished,
            'finished_at' => now(),
        ]);

        $this->getJson(route('events.operations.waiting.snapshot', $event))
            ->assertOk()
            ->assertJsonCount(1, 'tickets')
            ->assertJsonPath('tickets.0.id', $active->id)
            ->assertJsonPath('tickets.0.position.value', ParticipantStatus::Waiting->value)
            ->assertJsonPath('tickets.0.participant.gender_value', ParticipantGender::Male->value);
    }

    public function test_next_skip_and_goto_are_publicly_available_and_keep_ticket_history(): void
    {
        EventFacade::fake();
        [$event, $post] = $this->activeEventWithControlPost();
        $firstMale = $this->queueTicket($event, $post, ParticipantGender::Male, 1);
        $secondMale = $this->queueTicket($event, $post, ParticipantGender::Male, 2);

        $this->postJson(route('events.service-queues.call', [$event, $post, $firstMale]))
            ->assertOk()
            ->assertJsonPath('data.status', QueueTicketStatus::Calling->value);
        $this->assertSame(QueueTicketStatus::Calling, $firstMale->refresh()->status);
        EventFacade::assertDispatched(ServiceQueueUpdated::class);

        $this->postJson(route('events.service-queues.skip', [$event, $post, $firstMale]))
            ->assertOk()
            ->assertJsonPath('data.status', QueueTicketStatus::Skipped->value);
        $firstMale->refresh();
        $this->assertSame(QueueTicketStatus::Skipped, $firstMale->status);
        $this->assertNotNull($firstMale->skipped_at);
        $this->assertDatabaseHas('audit_logs', [
            'subject_id' => $firstMale->id,
            'subject_type' => QueueTicket::class,
            'action' => AuditAction::QueueTicketSkipped->value,
        ]);

        $this->postJson(route('events.service-queues.goto', [$event, $post, $firstMale]), [
            'queue_lane' => ParticipantGender::Male->value,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', QueueTicketStatus::Calling->value);
        $this->assertSame(QueueTicketStatus::Calling, $firstMale->refresh()->status);
        $this->assertSame(ParticipantStatus::Waiting, $firstMale->eventParticipant->refresh()->status);
    }

    public function test_goto_rejects_a_ticket_from_the_other_gender_lane_and_duplicate_next(): void
    {
        EventFacade::fake();
        [$event, $post] = $this->activeEventWithControlPost();
        $male = $this->queueTicket($event, $post, ParticipantGender::Male, 1);
        $otherMale = $this->queueTicket($event, $post, ParticipantGender::Male, 2);

        $this->postJson(route('events.service-queues.goto', [$event, $post, $male]), [
            'queue_lane' => ParticipantGender::Female->value,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('queue_ticket');

        $this->postJson(route('events.service-queues.call', [$event, $post, $male]))
            ->assertOk();
        $this->postJson(route('events.service-queues.call', [$event, $post, $male]))
            ->assertUnprocessable();
        $this->postJson(route('events.service-queues.call', [$event, $post, $otherMale]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('queue_ticket');

        $this->assertSame(QueueTicketStatus::Calling, $male->refresh()->status);
        $this->assertSame(QueueTicketStatus::Waiting, $otherMale->refresh()->status);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    /**
     * @return array{Event, ServicePost}
     */
    private function activeEventWithControlPost(): array
    {
        $event = Event::factory()->active()->create();
        $post = ServicePost::factory()->for($event)->create([
            'type' => ServicePostType::Health,
            'behavior' => ServicePostBehavior::HealthForm,
            'is_active' => true,
            'sequence' => 1,
        ]);

        return [$event, $post];
    }

    private function queueTicket(
        Event $event,
        ServicePost $post,
        ParticipantGender $gender,
        int $number,
    ): QueueTicket {
        $participant = Participant::factory()->create(['gender' => $gender]);
        $eventParticipant = EventParticipant::factory()
            ->for($event)
            ->for($participant)
            ->create([
                'status' => ParticipantStatus::Waiting,
                'current_service_post_id' => $post->id,
                'registration_number' => $number,
                'active_registration_number' => $number,
            ]);

        return QueueTicket::factory()->create([
            'event_id' => $event->id,
            'event_participant_id' => $eventParticipant->id,
            'service_post_id' => $post->id,
            'queue_type' => QueueType::General,
            'number' => $number,
            'status' => QueueTicketStatus::Waiting,
        ]);
    }
}
