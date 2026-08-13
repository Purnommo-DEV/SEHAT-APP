<?php

namespace Tests\Feature;

use App\Enums\ParticipantGender;
use App\Enums\ParticipantStatus;
use App\Enums\QueueTicketStatus;
use App\Enums\QueueType;
use App\Enums\ServicePostBehavior;
use App\Enums\ServicePostType;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Participant;
use App\Models\QueueTicket;
use App\Models\ServicePost;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationalPollingPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_waiting_snapshot_query_count_stays_bounded_with_many_active_participants(): void
    {
        [$event, $post] = $this->activeEventWithWaitingParticipants(40);
        $queryCount = 0;

        DB::listen(function (QueryExecuted $query) use (&$queryCount): void {
            $queryCount++;
        });

        $this->getJson(route('events.operations.waiting.snapshot', $event))
            ->assertOk()
            ->assertJsonCount(40, 'queue.tickets')
            ->assertJsonCount(40, 'queue.positions')
            ->assertJsonCount(40, 'stages.waiting')
            ->assertJsonPath('finished_count', 0);

        // The count is constant for 1 or 40 rows; relationships are eager-loaded.
        $this->assertLessThanOrEqual(17, $queryCount);
    }

    public function test_waiting_snapshot_limits_finished_history_without_changing_the_total(): void
    {
        $event = Event::factory()->active()->create();
        $post = ServicePost::factory()->for($event)->create([
            'type' => ServicePostType::Health,
            'behavior' => ServicePostBehavior::HealthForm,
            'is_active' => true,
            'sequence' => 1,
        ]);

        foreach (range(1, 40) as $number) {
            EventParticipant::factory()
                ->for($event)
                ->for(Participant::factory()->state([
                    'gender' => $number % 2 === 0 ? ParticipantGender::Female : ParticipantGender::Male,
                ]))
                ->create([
                    'status' => ParticipantStatus::Finished,
                    'current_service_post_id' => $post->id,
                    'registration_number' => $number,
                    'active_registration_number' => $number,
                    'registration_order' => $number,
                    'checked_in_at' => now(),
                    'completed_at' => now(),
                ]);
        }

        $this->getJson(route('events.operations.waiting.snapshot', $event))
            ->assertOk()
            ->assertJsonCount(30, 'stages.finished')
            ->assertJsonPath('finished_count', 40);
    }

    public function test_dashboard_and_monitor_polling_queries_stay_bounded_with_many_active_participants(): void
    {
        [$event] = $this->activeEventWithWaitingParticipants(40);
        $queryCount = 0;

        DB::listen(function (QueryExecuted $query) use (&$queryCount): void {
            $queryCount++;
        });

        $this->getJson(route('dashboard.data'))
            ->assertOk()
            ->assertJsonCount(30, 'current_positions');
        $dashboardQueries = $queryCount;

        $this->getJson(route('events.monitor.data', $event))
            ->assertOk()
            ->assertJsonCount(2, 'queues');
        $monitorQueries = $queryCount - $dashboardQueries;

        $this->assertLessThanOrEqual(15, $dashboardQueries);
        $this->assertLessThanOrEqual(13, $monitorQueries);
    }

    /** @return array{Event, ServicePost} */
    private function activeEventWithWaitingParticipants(int $count): array
    {
        $event = Event::factory()->active()->create();
        $post = ServicePost::factory()->for($event)->create([
            'type' => ServicePostType::Health,
            'behavior' => ServicePostBehavior::HealthForm,
            'is_active' => true,
            'sequence' => 1,
        ]);

        foreach (range(1, $count) as $number) {
            $participant = Participant::factory()->create([
                'gender' => $number % 2 === 0 ? ParticipantGender::Female : ParticipantGender::Male,
            ]);
            $eventParticipant = EventParticipant::factory()
                ->for($event)
                ->for($participant)
                ->create([
                    'status' => ParticipantStatus::Waiting,
                    'current_service_post_id' => $post->id,
                    'registration_number' => $number,
                    'active_registration_number' => $number,
                    'registration_order' => $number,
                    'checked_in_at' => now(),
                ]);

            QueueTicket::factory()->create([
                'event_id' => $event->id,
                'event_participant_id' => $eventParticipant->id,
                'service_post_id' => $post->id,
                'queue_type' => QueueType::General,
                'number' => $number,
                'status' => QueueTicketStatus::Waiting,
            ]);
        }

        return [$event, $post];
    }
}
