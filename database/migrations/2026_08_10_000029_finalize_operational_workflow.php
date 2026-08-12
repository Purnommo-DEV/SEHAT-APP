<?php

use App\Enums\ParticipantStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_participant_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('event_participant_id');
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['event_id', 'to_status', 'created_at'], 'participant_status_history_event_status_index');
            $table->foreign(['event_id', 'event_participant_id'], 'participant_status_history_event_participant_foreign')
                ->references(['event_id', 'id'])
                ->on('event_participants')
                ->cascadeOnDelete();
        });

        $this->migrateOperationalStatuses();
        $this->refreshParticipantStatusCheck();
        $this->seedHistoryForExistingParticipants();
    }

    public function down(): void
    {
        $this->restoreLegacyStatuses();
        $this->refreshParticipantStatusCheck();
        Schema::dropIfExists('event_participant_status_histories');
    }

    private function migrateOperationalStatuses(): void
    {
        DB::table('event_participants')
            ->whereIn('status', [
                ParticipantStatus::Registered->value,
                ParticipantStatus::CheckedIn->value,
                ParticipantStatus::WaitingService->value,
                ParticipantStatus::ServiceInProgress->value,
                ParticipantStatus::WaitingHealth->value,
                ParticipantStatus::HealthInProgress->value,
                ParticipantStatus::WaitingScreening->value,
                ParticipantStatus::WaitingDonor->value,
            ])
            ->update(['status' => ParticipantStatus::Waiting->value]);

        DB::table('event_participants')
            ->where('status', ParticipantStatus::DonationInProgress->value)
            ->update(['status' => ParticipantStatus::Donating->value]);

        DB::table('event_participants')
            ->whereIn('status', [
                ParticipantStatus::NotEligible->value,
                ParticipantStatus::DonationCompleted->value,
                ParticipantStatus::HealthCheckCompleted->value,
            ])
            ->update([
                'status' => ParticipantStatus::Finished->value,
                'completed_at' => DB::raw('COALESCE(completed_at, updated_at, created_at)'),
            ]);
    }

    private function restoreLegacyStatuses(): void
    {
        DB::table('event_participants')
            ->where('status', ParticipantStatus::Waiting->value)
            ->update(['status' => ParticipantStatus::WaitingScreening->value]);
        DB::table('event_participants')
            ->where('status', ParticipantStatus::HealthCheck->value)
            ->update(['status' => ParticipantStatus::WaitingHealth->value]);
        DB::table('event_participants')
            ->where('status', ParticipantStatus::Donating->value)
            ->update(['status' => ParticipantStatus::DonationInProgress->value]);
    }

    private function refreshParticipantStatusCheck(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'pgsql'], true)) {
            return;
        }

        $driver = DB::getDriverName();
        $clause = $driver === 'mysql'
            ? 'DROP CHECK event_participants_status_check'
            : 'DROP CONSTRAINT event_participants_status_check';

        DB::statement("ALTER TABLE event_participants {$clause}");
        $values = implode(', ', array_map(
            fn (ParticipantStatus $status): string => DB::getPdo()->quote($status->value),
            ParticipantStatus::cases(),
        ));
        DB::statement("ALTER TABLE event_participants ADD CONSTRAINT event_participants_status_check CHECK (status IN ({$values}))");
    }

    private function seedHistoryForExistingParticipants(): void
    {
        DB::table('event_participants')
            ->select(['id', 'event_id', 'status', 'updated_at', 'created_at'])
            ->orderBy('id')
            ->eachById(function (object $participant): void {
                DB::table('event_participant_status_histories')->insert([
                    'event_id' => $participant->event_id,
                    'event_participant_id' => $participant->id,
                    'from_status' => null,
                    'to_status' => $participant->status,
                    'changed_by' => null,
                    'created_at' => $participant->updated_at ?? $participant->created_at ?? now(),
                ]);
            });
    }
};
