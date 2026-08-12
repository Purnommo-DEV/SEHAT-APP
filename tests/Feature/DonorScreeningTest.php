<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\ParticipantGender;
use App\Enums\ParticipantStatus;
use App\Enums\QueueType;
use App\Enums\ScreeningResult;
use App\Enums\ServicePostType;
use App\Enums\UserRole;
use App\Events\DashboardUpdated;
use App\Events\ParticipantEligible;
use App\Events\ParticipantIneligible;
use App\Events\ParticipantMovedToDonation;
use App\Events\QueueUpdated;
use App\Events\ScreeningUpdated;
use App\Events\TVMonitorUpdated;
use App\Models\DonorScreening;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\HealthAssessment;
use App\Models\Participant;
use App\Models\ServicePost;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
use Tests\TestCase;

class DonorScreeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_screening_requires_authentication_and_permission(): void
    {
        $event = Event::factory()->active()->create();

        $this->get(route('events.screening.index', $event))
            ->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->get(route('events.screening.index', $event))
            ->assertForbidden();
    }

    public function test_eligible_decision_moves_participant_to_active_donation_post(): void
    {
        EventFacade::fake();
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        $eventParticipant = $this->waitingParticipant($event, $administrator);
        $donationPost = ServicePost::factory()->for($event)->create([
            'type' => ServicePostType::Donation,
            'sequence' => 3,
            'is_active' => true,
        ]);

        $this->actingAs($administrator)
            ->postJson(route('events.screening.store', [$event, $eventParticipant]), [
                'result' => ScreeningResult::Eligible->value,
                'reason' => 'Alasan ini tidak disimpan untuk hasil layak.',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Layak donor. Nomor antrean L001 diterbitkan.');

        $screening = DonorScreening::query()->firstOrFail();
        $eventParticipant->refresh();

        $this->assertSame(ScreeningResult::Eligible, $screening->result);
        $this->assertNull($screening->reason);
        $this->assertSame(ParticipantStatus::WaitingDonor, $eventParticipant->status);
        $this->assertSame($donationPost->id, $eventParticipant->current_service_post_id);
        $this->assertDatabaseHas('queue_tickets', [
            'event_participant_id' => $eventParticipant->id,
            'queue_type' => QueueType::MaleDonor->value,
            'number' => 1,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'subject_type' => DonorScreening::class,
            'subject_id' => $screening->id,
            'action' => AuditAction::ScreeningEligible->value,
        ]);
        EventFacade::assertDispatched(
            ScreeningUpdated::class,
            fn (ScreeningUpdated $broadcast): bool => $broadcast->eventParticipantId === $eventParticipant->id
                && $broadcast->result === ScreeningResult::Eligible
        );
        EventFacade::assertDispatched(ParticipantEligible::class);
        EventFacade::assertDispatched(ParticipantMovedToDonation::class);
        EventFacade::assertDispatched(QueueUpdated::class);
        EventFacade::assertDispatched(DashboardUpdated::class);
        EventFacade::assertDispatched(TVMonitorUpdated::class);
    }

    public function test_not_eligible_decision_is_terminal_and_keeps_optional_reason(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        $eventParticipant = $this->waitingParticipant($event, $administrator);
        $completionPost = ServicePost::factory()->for($event)->create([
            'type' => ServicePostType::Completion,
            'sequence' => 4,
            'is_active' => true,
        ]);
        EventFacade::fake();

        $this->actingAs($administrator)
            ->post(route('events.screening.store', [$event, $eventParticipant]), [
                'result' => ScreeningResult::NotEligible->value,
                'reason' => '  HB rendah  ',
            ])
            ->assertRedirect(route('events.screening.index', $event));

        $screening = DonorScreening::query()->firstOrFail();
        $eventParticipant->refresh();

        $this->assertSame(ScreeningResult::NotEligible, $screening->result);
        $this->assertSame('HB rendah', $screening->reason);
        $this->assertSame(ParticipantStatus::NotEligible, $eventParticipant->status);
        $this->assertSame($completionPost->id, $eventParticipant->current_service_post_id);
        EventFacade::assertDispatched(ParticipantIneligible::class);
        EventFacade::assertDispatched(QueueUpdated::class);
        EventFacade::assertDispatched(DashboardUpdated::class);
        EventFacade::assertDispatched(TVMonitorUpdated::class);
    }

    public function test_eligible_decision_requires_active_donation_post_and_rolls_back(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        $eventParticipant = $this->waitingParticipant($event, $administrator);

        $this->actingAs($administrator)
            ->from(route('events.screening.index', $event))
            ->post(route('events.screening.store', [$event, $eventParticipant]), [
                'result' => ScreeningResult::Eligible->value,
            ])
            ->assertSessionHasErrors('event');

        $this->assertDatabaseCount('donor_screenings', 0);
        $this->assertSame(ParticipantStatus::WaitingScreening, $eventParticipant->refresh()->status);
    }

    public function test_decision_is_idempotent_and_cannot_be_overwritten_by_duplicate_submission(): void
    {
        EventFacade::fake([ScreeningUpdated::class]);
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        $eventParticipant = $this->waitingParticipant($event, $administrator);
        ServicePost::factory()->for($event)->create([
            'type' => ServicePostType::Donation,
            'sequence' => 3,
        ]);

        $this->actingAs($administrator)
            ->post(route('events.screening.store', [$event, $eventParticipant]), [
                'result' => ScreeningResult::Eligible->value,
            ]);
        $this->actingAs($administrator)
            ->post(route('events.screening.store', [$event, $eventParticipant]), [
                'result' => ScreeningResult::NotEligible->value,
                'reason' => 'Pengiriman ulang',
            ])
            ->assertSessionHas('status', 'Hasil screening peserta ini sebelumnya sudah dicatat.');

        $this->assertDatabaseCount('donor_screenings', 1);
        $this->assertSame(ScreeningResult::Eligible, DonorScreening::query()->firstOrFail()->result);
        EventFacade::assertDispatchedTimes(ScreeningUpdated::class, 1);
    }

    public function test_screening_validates_result_and_scopes_participant_to_event(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        $otherEvent = Event::factory()->create(['created_by' => $administrator->id]);
        $eventParticipant = $this->waitingParticipant($event, $administrator);

        $this->actingAs($administrator)
            ->post(route('events.screening.store', [$event, $eventParticipant]), [
                'result' => 'unknown',
            ])
            ->assertSessionHasErrors('result');

        $this->actingAs($administrator)
            ->post(route('events.screening.store', [$otherEvent, $eventParticipant]), [
                'result' => ScreeningResult::NotEligible->value,
            ])
            ->assertNotFound();
    }

    public function test_screening_page_and_realtime_data_include_health_summary(): void
    {
        $administrator = $this->administrator();
        $event = Event::factory()->active()->create(['created_by' => $administrator->id]);
        $eventParticipant = $this->waitingParticipant($event, $administrator);

        $this->actingAs($administrator)
            ->get(route('events.screening.index', $event))
            ->assertRedirect(route('events.operations.health-check', $event));

        $this->actingAs($administrator)
            ->getJson(route('events.screening.data', $event))
            ->assertOk()
            ->assertJsonPath('data.0.id', $eventParticipant->id)
            ->assertJsonPath('data.0.health.blood_pressure', '120/80');
    }

    private function waitingParticipant(Event $event, User $actor): EventParticipant
    {
        $screeningPost = ServicePost::factory()->for($event)->create([
            'type' => ServicePostType::Screening,
            'sequence' => 2,
            'is_active' => true,
        ]);
        $eventParticipant = EventParticipant::factory()
            ->for($event)
            ->for(Participant::factory()->state(['gender' => ParticipantGender::Male]))
            ->create([
                'current_service_post_id' => $screeningPost->id,
                'status' => ParticipantStatus::WaitingScreening,
                'checked_in_at' => now(),
            ]);
        HealthAssessment::factory()->create([
            'event_id' => $event->id,
            'event_participant_id' => $eventParticipant->id,
            'blood_pressure' => '120/80',
            'created_by' => $actor->id,
        ]);

        return $eventParticipant;
    }

    private function administrator(): User
    {
        $this->seed(RolePermissionSeeder::class);

        return User::query()->role(UserRole::Administrator->value)->firstOrFail();
    }
}
