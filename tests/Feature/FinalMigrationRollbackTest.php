<?php

namespace Tests\Feature;

use App\Enums\ParticipantGender;
use App\Enums\ParticipantServiceType;
use App\Enums\ServicePostBehavior;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Participant;
use App\Models\ServicePost;
use App\Models\User;
use App\Services\CheckIn\CheckInService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

class FinalMigrationRollbackTest extends TestCase
{
    use RefreshDatabase;

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_number_reuse_is_normalized_before_legacy_unique_indexes_are_restored(): void
    {
        $event = Event::factory()->active()->create();
        ServicePost::factory()->for($event)->create([
            'code' => 'health',
            'behavior' => ServicePostBehavior::HealthForm,
            'sequence' => 1,
            'is_active' => true,
        ]);
        $actor = User::factory()->create();
        $checkInService = app(CheckInService::class);
        $cancelled = $checkInService->checkIn(
            $event,
            Participant::factory()->create(['gender' => ParticipantGender::Male]),
            $actor,
            [ParticipantServiceType::HealthCheck],
        );
        $checkInService->cancel($event, $cancelled->eventParticipant, $actor);
        $replacement = $checkInService->checkIn(
            $event,
            Participant::factory()->create(['gender' => ParticipantGender::Female]),
            $actor,
            [ParticipantServiceType::HealthCheck],
        );

        $this->assertSame(
            $cancelled->eventParticipant->registration_number,
            $replacement->eventParticipant->registration_number,
        );

        $migration = require database_path(
            'migrations/2026_07_30_000025_finalize_sop_numbering_and_timestamps.php',
        );
        $migration->down();

        $this->assertFalse(Schema::hasColumn('event_participants', 'active_registration_number'));
        $this->assertFalse(Schema::hasColumn('queue_tickets', 'active_number'));
        $this->assertCount(
            2,
            array_unique(EventParticipant::query()->orderBy('id')->pluck('registration_number')->all()),
        );
    }
}
