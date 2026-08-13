<?php

namespace Tests\Feature;

use App\Enums\ParticipantStatus;
use App\Enums\QueueTicketStatus;
use App\Enums\QueueType;
use App\Enums\ScreeningResult;
use App\Enums\ServicePostBehavior;
use App\Enums\ServicePostType;
use App\Enums\UserRole;
use App\Models\DonorScreening;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\HealthAssessment;
use App\Models\Participant;
use App\Models\QueueTicket;
use App\Models\ServicePost;
use App\Models\User;
use App\Services\Report\ReportService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_require_authentication_and_permission(): void
    {
        $this->get(route('reports.index'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->get(route('reports.index'))
            ->assertForbidden();
    }

    public function test_administrator_can_view_event_report_and_json_recap(): void
    {
        $administrator = $this->administrator();
        [$event, $eventParticipant] = $this->reportData($administrator);

        $this->actingAs($administrator)
            ->get(route('reports.index', ['event_id' => $event->id]))
            ->assertOk()
            ->assertSee('Laporan Event')
            ->assertSee('Ahmad Laporan')
            ->assertSee('L-001');

        $this->actingAs($administrator)
            ->getJson(route('reports.data', ['event_id' => $event->id]))
            ->assertOk()
            ->assertJsonPath('event.id', $event->id)
            ->assertJsonPath('metrics.total_participants', 1)
            ->assertJsonPath('metrics.donation_completed', 1)
            ->assertJsonPath('records.0.id', $eventParticipant->id)
            ->assertJsonPath('records.0.name', 'Ahmad Laporan')
            ->assertJsonPath('records.0.general_queue_number', '001')
            ->assertJsonPath('records.0.donor_queue_number', 'L-001')
            ->assertJsonPath('records.0.screening_result', 'Layak Donor');
    }

    public function test_report_exports_actual_excel_and_pdf_files(): void
    {
        $administrator = $this->administrator();
        [$event] = $this->reportData($administrator);

        $excel = $this->actingAs($administrator)
            ->get(route('reports.excel', ['event_id' => $event->id]));
        $excel->assertOk()->assertDownload();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            (string) $excel->headers->get('content-type'),
        );

        $pdf = $this->actingAs($administrator)
            ->get(route('reports.pdf', ['event_id' => $event->id]));
        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_export_requires_explicit_or_resolvable_event_and_validates_event_id(): void
    {
        $administrator = $this->administrator();

        $this->actingAs($administrator)
            ->get(route('reports.excel'))
            ->assertNotFound();

        $this->actingAs($administrator)
            ->get(route('reports.index', ['event_id' => 999999]))
            ->assertSessionHasErrors('event_id');
    }

    public function test_report_uses_service_post_history_as_its_primary_dynamic_output(): void
    {
        $actor = $this->administrator();
        $event = Event::factory()->create(['created_by' => $actor->id]);
        $registration = ServicePost::factory()->for($event)->create([
            'name' => 'Verifikasi Dokumen',
            'type' => ServicePostType::Custom,
            'behavior' => ServicePostBehavior::ConfirmationOnly,
            'queue_prefix' => 'V',
            'sequence' => 1,
        ]);
        $consultation = ServicePost::factory()->for($event)->create([
            'name' => 'Konsultasi Gizi',
            'type' => ServicePostType::Custom,
            'behavior' => ServicePostBehavior::CustomForm,
            'queue_prefix' => 'G',
            'sequence' => 2,
        ]);
        $completion = ServicePost::factory()->for($event)->create([
            'name' => 'Konfirmasi Pulang',
            'type' => ServicePostType::Custom,
            'behavior' => ServicePostBehavior::ConfirmationOnly,
            'queue_prefix' => 'K',
            'sequence' => 3,
        ]);
        $eventParticipant = EventParticipant::factory()->for($event)->create([
            'status' => ParticipantStatus::Finished,
            'checked_in_at' => now(),
        ]);

        foreach ([$registration, $consultation, $completion] as $post) {
            QueueTicket::factory()->create([
                'event_id' => $event->id,
                'event_participant_id' => $eventParticipant->id,
                'service_post_id' => $post->id,
                'queue_type' => QueueType::General,
                'number' => 1,
                'status' => QueueTicketStatus::Finished,
                'finished_at' => now(),
            ]);
        }

        $snapshot = app(ReportService::class)->snapshot($event);
        $record = $snapshot->records[0];

        $this->assertSame(1, $snapshot->metrics['finished']);
        $this->assertSame(['Verifikasi Dokumen', 'Konsultasi Gizi', 'Konfirmasi Pulang'], array_column($record['service_history'], 'post_name'));
        $this->assertSame(['V-001', 'G-001', 'K-001'], array_column($record['service_history'], 'queue_number'));
        $this->assertNull($record['donor_queue_number']);
    }

    public function test_report_snapshot_query_count_is_bounded_for_many_participants_and_posts(): void
    {
        $event = Event::factory()->create();
        $posts = collect(range(1, 3))->map(fn (int $sequence): ServicePost => ServicePost::factory()
            ->for($event)
            ->create([
                'type' => ServicePostType::Custom,
                'behavior' => ServicePostBehavior::ConfirmationOnly,
                'sequence' => $sequence,
            ]));
        $participants = EventParticipant::factory()->count(40)->for($event)->create([
            'status' => ParticipantStatus::WaitingService,
            'checked_in_at' => now(),
        ]);

        foreach ($participants as $index => $eventParticipant) {
            $post = $posts[$index % $posts->count()];
            QueueTicket::factory()->create([
                'event_id' => $event->id,
                'event_participant_id' => $eventParticipant->id,
                'service_post_id' => $post->id,
                'queue_type' => QueueType::General,
                'number' => $index + 1,
                'status' => QueueTicketStatus::Waiting,
            ]);
        }

        $queryCount = 0;
        DB::listen(function (QueryExecuted $query) use (&$queryCount): void {
            $queryCount++;
        });

        $snapshot = app(ReportService::class)->snapshot($event);

        $this->assertCount(40, $snapshot->records);
        $this->assertLessThanOrEqual(14, $queryCount);
    }

    /**
     * @return array{Event, EventParticipant}
     */
    private function reportData(User $actor): array
    {
        $event = Event::factory()->create([
            'code' => 'LAPOR-2026',
            'name' => 'Event Laporan',
            'created_by' => $actor->id,
        ]);
        $healthPost = ServicePost::factory()->for($event)->create([
            'type' => ServicePostType::Health,
            'sequence' => 1,
        ]);
        $donationPost = ServicePost::factory()->for($event)->create([
            'type' => ServicePostType::Donation,
            'sequence' => 2,
        ]);
        $participant = Participant::factory()->create([
            'name' => 'Ahmad Laporan',
            'phone' => '081234567890',
        ]);
        $eventParticipant = EventParticipant::factory()->for($event)->for($participant)->create([
            'current_service_post_id' => $donationPost->id,
            'status' => ParticipantStatus::DonationCompleted,
            'checked_in_at' => now()->subHour(),
        ]);
        HealthAssessment::factory()->create([
            'event_id' => $event->id,
            'event_participant_id' => $eventParticipant->id,
            'blood_pressure' => '120/80',
            'blood_sugar' => 100,
            'created_by' => $actor->id,
        ]);
        DonorScreening::factory()->create([
            'event_id' => $event->id,
            'event_participant_id' => $eventParticipant->id,
            'result' => ScreeningResult::Eligible,
            'screened_by' => $actor->id,
        ]);
        QueueTicket::factory()->create([
            'event_id' => $event->id,
            'event_participant_id' => $eventParticipant->id,
            'service_post_id' => $healthPost->id,
            'queue_type' => QueueType::General,
            'number' => 1,
            'status' => QueueTicketStatus::Finished,
            'finished_at' => now()->subMinutes(30),
        ]);
        QueueTicket::factory()->create([
            'event_id' => $event->id,
            'event_participant_id' => $eventParticipant->id,
            'service_post_id' => $donationPost->id,
            'queue_type' => QueueType::MaleDonor,
            'number' => 1,
            'status' => QueueTicketStatus::Finished,
            'finished_at' => now(),
        ]);

        return [$event, $eventParticipant];
    }

    private function administrator(): User
    {
        $this->seed(RolePermissionSeeder::class);

        return User::query()->role(UserRole::Administrator->value)->firstOrFail();
    }
}
