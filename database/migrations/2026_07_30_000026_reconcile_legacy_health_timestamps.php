<?php

use App\Enums\ParticipantServiceStatus;
use App\Enums\ParticipantServiceType;
use App\Enums\ServicePostBehavior;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $healthPostIds = DB::table('service_posts')
            ->where('behavior', ServicePostBehavior::HealthForm->value)
            ->pluck('id');

        foreach (DB::table('event_participant_services')
            ->where('service', ParticipantServiceType::HealthCheck->value)
            ->orderBy('id')
            ->get() as $service) {
            $ticket = DB::table('queue_tickets')
                ->where('event_participant_id', $service->event_participant_id)
                ->whereIn('service_post_id', $healthPostIds)
                ->oldest('id');
            $assessment = DB::table('health_assessments')
                ->where('event_participant_id', $service->event_participant_id)
                ->oldest('id');
            $ticketServedAt = (clone $ticket)->value('served_at');
            $ticketFinishedAt = (clone $ticket)->value('finished_at');
            $assessmentCreatedAt = (clone $assessment)->value('created_at');
            $assessmentUpdatedAt = (clone $assessment)->value('updated_at');
            $startedAt = $ticketServedAt
                ?? $service->started_at
                ?? $assessmentCreatedAt;
            $completedAt = $ticketFinishedAt
                ?? $assessmentCreatedAt
                ?? $assessmentUpdatedAt
                ?? $service->completed_at;
            $updates = [];

            if ($startedAt !== null) {
                $updates['started_at'] = $startedAt;
            }

            if ($completedAt !== null) {
                $updates['completed_at'] = $completedAt;
            }

            if ($updates !== []) {
                DB::table('event_participant_services')
                    ->where('id', $service->id)
                    ->update($updates);
            }
        }

        $terminalStatuses = [
            ParticipantServiceStatus::Completed->value,
            ParticipantServiceStatus::NotEligible->value,
            ParticipantServiceStatus::Cancelled->value,
        ];
        $participantIds = DB::table('event_participant_services')
            ->distinct()
            ->pluck('event_participant_id');

        foreach ($participantIds as $participantId) {
            $services = DB::table('event_participant_services')
                ->where('event_participant_id', $participantId);

            if ((clone $services)->whereNotIn('status', $terminalStatuses)->exists()) {
                DB::table('event_participants')
                    ->where('id', $participantId)
                    ->update(['completed_at' => null]);

                continue;
            }

            $completedAt = (clone $services)->max('completed_at');

            if ($completedAt !== null) {
                DB::table('event_participants')
                    ->where('id', $participantId)
                    ->update(['completed_at' => $completedAt]);
            }
        }
    }

    /**
     * Historical timestamp reconciliation is intentionally retained on rollback.
     */
    public function down(): void {}
};
