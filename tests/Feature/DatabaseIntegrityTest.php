<?php

namespace Tests\Feature;

use App\Enums\QueueTicketStatus;
use App\Enums\QueueType;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\QueueTicket;
use App\Models\ServicePost;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_redundant_indexes_are_removed_and_scoped_indexes_exist(): void
    {
        $servicePostIndexes = $this->sqliteIndexes('service_posts');
        $eventParticipantIndexes = $this->sqliteIndexes('event_participants');
        $screeningIndexes = $this->sqliteIndexes('donor_screenings');

        $this->assertContains('service_posts_event_type_active_index', $servicePostIndexes);
        $this->assertNotContains('service_posts_type_index', $servicePostIndexes);
        $this->assertNotContains('service_posts_event_id_is_active_index', $servicePostIndexes);
        $this->assertNotContains('event_participants_status_index', $eventParticipantIndexes);
        $this->assertNotContains('donor_screenings_result_index', $screeningIndexes);
    }

    public function test_queue_ticket_factory_keeps_event_relations_consistent(): void
    {
        $ticket = QueueTicket::factory()->create();

        $this->assertSame($ticket->event_id, $ticket->eventParticipant->event_id);
        $this->assertSame($ticket->event_id, $ticket->servicePost->event_id);
    }

    public function test_cross_event_queue_ticket_is_rejected_by_database(): void
    {
        $event = Event::factory()->create();
        $otherEvent = Event::factory()->create();
        $eventParticipant = EventParticipant::factory()->for($event)->create();
        $otherPost = ServicePost::factory()->for($otherEvent)->create();

        $this->expectException(QueryException::class);

        QueueTicket::query()->create([
            'event_id' => $event->id,
            'event_participant_id' => $eventParticipant->id,
            'service_post_id' => $otherPost->id,
            'queue_type' => QueueType::General,
            'number' => 1,
            'status' => QueueTicketStatus::Waiting,
        ]);
    }

    /**
     * @return list<string>
     */
    private function sqliteIndexes(string $table): array
    {
        return collect(DB::select("PRAGMA index_list('{$table}')"))
            ->pluck('name')
            ->map(fn (mixed $name): string => (string) $name)
            ->values()
            ->all();
    }
}
