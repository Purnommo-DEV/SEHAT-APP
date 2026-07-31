<?php

namespace Tests\Feature;

use App\Enums\ParticipantStatus;
use App\Enums\ServicePostType;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\ServicePost;
use App\Services\Dashboard\DashboardService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_query_count_is_bounded_when_participant_volume_grows(): void
    {
        $event = Event::factory()->active()->create();
        $post = ServicePost::factory()->for($event)->create([
            'type' => ServicePostType::Health,
            'sequence' => 1,
        ]);
        EventParticipant::factory()
            ->count(60)
            ->for($event)
            ->for($post, 'currentServicePost')
            ->create([
                'status' => ParticipantStatus::WaitingHealth,
                'checked_in_at' => now(),
            ]);
        $queryCount = 0;
        DB::listen(function (QueryExecuted $query) use (&$queryCount): void {
            $queryCount++;
        });

        $snapshot = app(DashboardService::class)->snapshot();

        $this->assertSame(60, $snapshot['metrics']['total_participants']);
        $this->assertCount(30, $snapshot['current_positions']);
        $this->assertLessThanOrEqual(10, $queryCount);
    }
}
