<?php

use App\Enums\ParticipantServiceType;
use App\Enums\ServicePostBehavior;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $screeningPostIds = DB::table('service_posts')
            ->where('behavior', ServicePostBehavior::ScreeningForm->value)
            ->pluck('id');

        foreach (DB::table('event_participant_services')
            ->where('service', ParticipantServiceType::Donor->value)
            ->orderBy('id')
            ->get() as $service) {
            $ticket = DB::table('queue_tickets')
                ->where('event_participant_id', $service->event_participant_id)
                ->whereIn('service_post_id', $screeningPostIds)
                ->oldest('id');
            $screening = DB::table('donor_screenings')
                ->where('event_participant_id', $service->event_participant_id)
                ->oldest('id');
            $ticketServedAt = (clone $ticket)->value('served_at');
            $ticketFinishedAt = (clone $ticket)->value('finished_at');
            $screeningCreatedAt = (clone $screening)->value('created_at');
            $screeningUpdatedAt = (clone $screening)->value('updated_at');
            $eligibilityStartedAt = $ticketServedAt
                ?? $service->eligibility_started_at
                ?? $screeningCreatedAt;
            $eligibilityCompletedAt = $ticketFinishedAt
                ?? $screeningUpdatedAt
                ?? $screeningCreatedAt
                ?? $service->eligibility_completed_at;
            $updates = [];

            if ($eligibilityStartedAt !== null) {
                $updates['eligibility_started_at'] = $eligibilityStartedAt;
            }

            if ($eligibilityCompletedAt !== null) {
                $updates['eligibility_completed_at'] = $eligibilityCompletedAt;
            }

            if ($updates !== []) {
                DB::table('event_participant_services')
                    ->where('id', $service->id)
                    ->update($updates);
            }
        }
    }

    /**
     * Historical timestamp reconciliation is intentionally retained on rollback.
     */
    public function down(): void {}
};
