<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\DonorNumberMode;
use App\Enums\ParticipantGender;
use App\Enums\ParticipantStatus;
use App\Enums\QueueTicketStatus;
use App\Enums\QueueType;
use App\Enums\ScreeningResult;
use App\Enums\ServicePostType;
use App\Enums\UserRole;
use App\Events\DashboardUpdated;
use App\Events\DonorQueueUpdated;
use App\Events\ParticipantDonationCompleted;
use App\Events\QueueUpdated;
use App\Events\TVMonitorUpdated;
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

class DonationQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_donor_queue_requires_authentication_and_permission(): void
    {
        $event = Event::factory()->active()->create();

        $this->get(route('events.donation.index', $event))
            ->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->get(route('events.donation.index', $event))
            ->assertForbidden();
    }

    public function test_eligible_screening_issues_independent_gender_queue_numbers(): void
    {
        EventFacade::fake([DonorQueueUpdated::class]);
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        $event->settings()->update([
            'donor_number_mode' => DonorNumberMode::GenderSeparated,
        ]);
        $screeningPost = ServicePost::factory()->for($event)->create([
            'type' => ServicePostType::Screening,
            'sequence' => 2,
        ]);
        ServicePost::factory()->for($event)->create([
            'type' => ServicePostType::Donation,
            'sequence' => 3,
        ]);
        $firstMale = $this->waitingForScreening($event, $screeningPost, ParticipantGender::Male);
        $secondMale = $this->waitingForScreening($event, $screeningPost, ParticipantGender::Male);
        $female = $this->waitingForScreening($event, $screeningPost, ParticipantGender::Female);

        foreach ([$firstMale, $secondMale, $female] as $eventParticipant) {
            $this->actingAs($administrator)
                ->post(route('events.screening.store', [$event, $eventParticipant]), [
                    'result' => ScreeningResult::Eligible->value,
                ])
                ->assertRedirect(route('events.screening.index', $event));
        }

        $tickets = QueueTicket::query()
            ->whereIn('queue_type', [QueueType::MaleDonor->value, QueueType::FemaleDonor->value])
            ->with('event.settings')
            ->orderBy('id')
            ->get();

        $this->assertSame(['L001', 'L002', 'P001'], $tickets->map->formattedNumber()->all());
        $this->assertSame([1, 2, 1], $tickets->pluck('number')->all());
        $this->assertDatabaseHas('audit_logs', [
            'subject_type' => QueueTicket::class,
            'subject_id' => $tickets->first()->id,
            'action' => AuditAction::DonorTicketIssued->value,
        ]);
        EventFacade::assertDispatchedTimes(DonorQueueUpdated::class, 3);
    }

    public function test_legacy_screening_reuses_registration_numbers_even_when_the_old_mode_is_global(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        $event->settings()->update([
            'donor_number_mode' => DonorNumberMode::Global,
        ]);
        $screeningPost = ServicePost::factory()->for($event)->create([
            'type' => ServicePostType::Screening,
            'sequence' => 2,
        ]);
        ServicePost::factory()->for($event)->create([
            'type' => ServicePostType::Donation,
            'sequence' => 3,
        ]);
        $participants = [
            $this->waitingForScreening($event, $screeningPost, ParticipantGender::Male),
            $this->waitingForScreening($event, $screeningPost, ParticipantGender::Female),
        ];

        foreach ($participants as $eventParticipant) {
            $this->actingAs($administrator)
                ->post(route('events.screening.store', [$event, $eventParticipant]), [
                    'result' => ScreeningResult::Eligible->value,
                ])
                ->assertRedirect(route('events.screening.index', $event));
        }

        $tickets = QueueTicket::query()
            ->whereIn('queue_type', [QueueType::MaleDonor->value, QueueType::FemaleDonor->value])
            ->with('event.settings')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $tickets);
        $this->assertSame([1, 1], $tickets->pluck('number')->all());
        $this->assertSame(['L001', 'P001'], $tickets->map->formattedNumber()->all());
    }

    public function test_donor_ticket_can_be_called_skipped_and_recalled(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        $ticket = $this->donorTicket($event);
        EventFacade::fake();

        $this->actingAs($administrator)
            ->postJson(route('events.donation.call', [$event, $ticket]))
            ->assertOk()
            ->assertJsonPath('data.status', QueueTicketStatus::Calling->value);
        $this->assertSame(QueueTicketStatus::Calling, $ticket->refresh()->status);

        $this->actingAs($administrator)
            ->post(route('events.donation.skip', [$event, $ticket]))
            ->assertRedirect();
        $this->assertSame(QueueTicketStatus::Skipped, $ticket->refresh()->status);

        $this->actingAs($administrator)
            ->post(route('events.donation.call', [$event, $ticket]))
            ->assertRedirect();
        $this->assertSame(QueueTicketStatus::Calling, $ticket->refresh()->status);
        EventFacade::assertDispatchedTimes(QueueUpdated::class, 3);
        EventFacade::assertDispatchedTimes(DashboardUpdated::class, 3);
        EventFacade::assertDispatchedTimes(TVMonitorUpdated::class, 3);
        EventFacade::assertDispatchedTimes(DonorQueueUpdated::class, 3);
    }

    public function test_start_and_complete_donation_update_ticket_and_participant_atomically(): void
    {
        EventFacade::fake();
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        $ticket = $this->donorTicket($event, QueueTicketStatus::Calling);
        $completionPost = ServicePost::factory()->for($event)->create([
            'type' => ServicePostType::Completion,
            'sequence' => 4,
        ]);

        $this->actingAs($administrator)
            ->post(route('events.donation.start', [$event, $ticket]))
            ->assertRedirect();

        $this->assertSame(QueueTicketStatus::Serving, $ticket->refresh()->status);
        $this->assertSame(
            ParticipantStatus::DonationInProgress,
            $ticket->eventParticipant->refresh()->status,
        );

        $this->actingAs($administrator)
            ->post(route('events.donation.complete', [$event, $ticket]))
            ->assertRedirect();

        $eventParticipant = $ticket->eventParticipant->refresh();
        $this->assertSame(QueueTicketStatus::Finished, $ticket->refresh()->status);
        $this->assertNotNull($ticket->finished_at);
        $this->assertSame(ParticipantStatus::DonationCompleted, $eventParticipant->status);
        $this->assertSame($completionPost->id, $eventParticipant->current_service_post_id);
        $this->assertDatabaseHas('audit_logs', [
            'subject_type' => QueueTicket::class,
            'subject_id' => $ticket->id,
            'action' => AuditAction::DonationCompleted->value,
        ]);
        EventFacade::assertDispatchedTimes(DonorQueueUpdated::class, 2);
        EventFacade::assertDispatched(ParticipantDonationCompleted::class);
        EventFacade::assertDispatchedTimes(QueueUpdated::class, 2);
        EventFacade::assertDispatchedTimes(DashboardUpdated::class, 2);
        EventFacade::assertDispatchedTimes(TVMonitorUpdated::class, 2);
    }

    public function test_donation_cannot_complete_before_service_starts(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        $ticket = $this->donorTicket($event);

        $this->actingAs($administrator)
            ->from(route('events.donation.index', $event))
            ->post(route('events.donation.complete', [$event, $ticket]))
            ->assertSessionHasErrors('queue_ticket');

        $this->assertSame(QueueTicketStatus::Waiting, $ticket->refresh()->status);
        $this->assertSame(ParticipantStatus::WaitingDonor, $ticket->eventParticipant->refresh()->status);
    }

    public function test_donation_page_data_and_ticket_binding_are_scoped_to_event(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        $otherEvent = Event::factory()->create(['created_by' => $administrator->id]);
        $ticket = $this->donorTicket($event);

        $this->actingAs($administrator)
            ->get(route('events.donation.index', $event))
            ->assertRedirect(route('events.operations.health-check', $event));

        $this->actingAs($administrator)
            ->getJson(route('events.donation.data', $event))
            ->assertOk()
            ->assertJsonPath('data.0.id', $ticket->id)
            ->assertJsonPath('data.0.formatted_number', 'L001');

        $this->actingAs($administrator)
            ->post(route('events.donation.call', [$otherEvent, $ticket]))
            ->assertNotFound();
    }

    private function waitingForScreening(
        Event $event,
        ServicePost $screeningPost,
        ParticipantGender $gender,
    ): EventParticipant {
        return EventParticipant::factory()
            ->for($event)
            ->for(Participant::factory()->state(['gender' => $gender]))
            ->create([
                'current_service_post_id' => $screeningPost->id,
                'status' => ParticipantStatus::WaitingScreening,
                'checked_in_at' => now(),
            ]);
    }

    private function donorTicket(
        Event $event,
        QueueTicketStatus $status = QueueTicketStatus::Waiting,
    ): QueueTicket {
        $donationPost = ServicePost::factory()->for($event)->create([
            'type' => ServicePostType::Donation,
            'sequence' => 3,
            'is_active' => true,
        ]);
        $participantStatus = $status === QueueTicketStatus::Serving
            ? ParticipantStatus::DonationInProgress
            : ParticipantStatus::WaitingDonor;
        $eventParticipant = EventParticipant::factory()
            ->for($event)
            ->for(Participant::factory()->state(['gender' => ParticipantGender::Male]))
            ->create([
                'current_service_post_id' => $donationPost->id,
                'status' => $participantStatus,
                'checked_in_at' => now(),
            ]);

        return QueueTicket::factory()->create([
            'event_id' => $event->id,
            'event_participant_id' => $eventParticipant->id,
            'service_post_id' => $donationPost->id,
            'queue_type' => QueueType::MaleDonor,
            'number' => 1,
            'status' => $status,
            'called_at' => in_array($status, [QueueTicketStatus::Calling, QueueTicketStatus::Serving], true) ? now() : null,
            'served_at' => $status === QueueTicketStatus::Serving ? now() : null,
        ]);
    }

    private function administrator(): User
    {
        $this->seed(RolePermissionSeeder::class);

        return User::query()->role(UserRole::Administrator->value)->firstOrFail();
    }
}
