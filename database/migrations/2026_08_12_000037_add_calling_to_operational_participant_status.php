<?php

use App\Enums\ParticipantStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->refreshStatusCheck();
    }

    public function down(): void
    {
        DB::table('event_participants')
            ->where('status', ParticipantStatus::Calling->value)
            ->update(['status' => ParticipantStatus::Waiting->value]);

        $this->refreshStatusCheck();
    }

    private function refreshStatusCheck(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'pgsql'], true)) {
            return;
        }

        $clause = 'DROP CONSTRAINT event_participants_status_check';
        DB::statement("ALTER TABLE event_participants {$clause}");

        $values = implode(', ', array_map(
            fn (ParticipantStatus $status): string => DB::getPdo()->quote($status->value),
            ParticipantStatus::cases(),
        ));
        DB::statement("ALTER TABLE event_participants ADD CONSTRAINT event_participants_status_check CHECK (status IN ({$values}))");
    }
};
