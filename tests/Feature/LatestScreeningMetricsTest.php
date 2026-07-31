<?php

namespace Tests\Feature;

use App\Enums\ParticipantServiceStatus;
use App\Enums\ParticipantServiceType;
use App\Enums\ParticipantStatus;
use App\Enums\ScreeningResult;
use App\Enums\ServicePostBehavior;
use App\Http\Resources\ScreeningParticipantResource;
use App\Models\DonorScreening;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventParticipantService;
use App\Models\Participant;
use App\Models\ServicePost;
use App\Models\User;
use App\Services\Dashboard\DashboardService;
use App\Services\Report\ReportService;
use App\Services\Screening\DonorScreeningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LatestScreeningMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_and_report_count_only_the_latest_screening_per_participant(): void
    {
        $event = Event::factory()->active()->create();
        $participant = EventParticipant::factory()
            ->for($event)
            ->for(Participant::factory())
            ->create([
                'status' => ParticipantStatus::NotEligible,
                'checked_in_at' => now()->subHour(),
            ]);
        EventParticipantService::factory()->create([
            'event_id' => $event->id,
            'event_participant_id' => $participant->id,
            'service' => ParticipantServiceType::Donor,
            'status' => ParticipantServiceStatus::NotEligible,
        ]);
        $firstScreeningPost = $this->screeningPost($event, 'screening-awal', 1);
        $latestScreeningPost = $this->screeningPost($event, 'screening-ulang', 2);
        $actor = User::factory()->create();

        DonorScreening::factory()->create([
            'event_id' => $event->id,
            'event_participant_id' => $participant->id,
            'service_post_id' => $firstScreeningPost->id,
            'result' => ScreeningResult::Eligible,
            'screened_by' => $actor->id,
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);
        DonorScreening::factory()->create([
            'event_id' => $event->id,
            'event_participant_id' => $participant->id,
            'service_post_id' => $latestScreeningPost->id,
            'result' => ScreeningResult::NotEligible,
            'reason' => 'Keputusan pemeriksaan ulang.',
            'screened_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $dashboard = app(DashboardService::class)->snapshot();
        $report = app(ReportService::class)->snapshot($event);
        $screeningDeskParticipant = app(DonorScreeningService::class)
            ->participantsForEvent($event)
            ->firstOrFail();
        $screeningDesk = (new ScreeningParticipantResource($screeningDeskParticipant))->resolve();

        $this->assertSame(0, $dashboard['metrics']['eligible_donor']);
        $this->assertSame(1, $dashboard['metrics']['not_eligible_donor']);
        $this->assertSame(0, $report->metrics['eligible_donor']);
        $this->assertSame(1, $report->metrics['not_eligible_donor']);
        $this->assertSame(
            ScreeningResult::NotEligible->label(),
            $report->records[0]['screening_result'],
        );
        $this->assertSame('Keputusan pemeriksaan ulang.', $report->records[0]['screening_reason']);
        $this->assertSame(
            ScreeningResult::NotEligible->value,
            $screeningDesk['screening']['result'],
        );
        $this->assertSame('Keputusan pemeriksaan ulang.', $screeningDesk['screening']['reason']);
    }

    private function screeningPost(Event $event, string $code, int $sequence): ServicePost
    {
        return ServicePost::factory()->for($event)->create([
            'code' => $code,
            'name' => str($code)->title()->toString(),
            'behavior' => ServicePostBehavior::ScreeningForm,
            'sequence' => $sequence,
            'is_active' => true,
        ]);
    }
}
