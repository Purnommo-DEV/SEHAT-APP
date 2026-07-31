<?php

use App\Enums\ParticipantStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $mapping = [
            'waiting_health' => ParticipantStatus::WaitingService->value,
            'waiting_screening' => ParticipantStatus::WaitingService->value,
            'waiting_donor' => ParticipantStatus::WaitingService->value,
            'health_in_progress' => ParticipantStatus::ServiceInProgress->value,
            'donation_in_progress' => ParticipantStatus::ServiceInProgress->value,
            'checked_in' => ParticipantStatus::WaitingService->value,
        ];

        foreach ($mapping as $legacyStatus => $currentStatus) {
            DB::table('event_participants')
                ->where('status', $legacyStatus)
                ->update(['status' => $currentStatus]);
        }
    }

    public function down(): void
    {
        // The generic status is intentionally retained on rollback; mapping it
        // back would lose the service-post-specific state that the new workflow stores.
    }
};
