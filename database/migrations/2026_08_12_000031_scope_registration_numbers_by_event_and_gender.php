<?php

use App\Enums\ParticipantGender;
use App\Enums\RegistrationNumberFormat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_UNIQUE = 'event_participants_event_active_registration_unique';

    private const SCOPED_UNIQUE = 'event_participants_event_registration_scope_active_unique';

    public function up(): void
    {
        Schema::table('event_participants', function (Blueprint $table): void {
            $table->string('registration_number_scope', 32)
                ->nullable()
                ->after('registration_number');
        });

        $genders = DB::table('event_participants')
            ->join('participants', 'participants.id', '=', 'event_participants.participant_id')
            ->orderBy('event_participants.id')
            ->pluck('participants.gender', 'event_participants.id');

        foreach ($genders as $eventParticipantId => $gender) {
            $scope = ParticipantGender::tryFrom((string) $gender);

            if (! $scope instanceof ParticipantGender) {
                continue;
            }

            DB::table('event_participants')
                ->where('id', $eventParticipantId)
                ->update(['registration_number_scope' => "gender:{$scope->value}"]);
        }

        Schema::table('event_participants', function (Blueprint $table): void {
            $table->dropUnique(self::LEGACY_UNIQUE);
            $table->unique(
                ['event_id', 'registration_number_scope', 'active_registration_number'],
                self::SCOPED_UNIQUE,
            );
        });

        DB::table('event_settings')->update([
            'registration_number_format' => RegistrationNumberFormat::GenderPrefix->value,
        ]);
    }

    public function down(): void
    {
        $this->normalizeToGlobalRegistrationNumbers();

        Schema::table('event_participants', function (Blueprint $table): void {
            $table->dropUnique(self::SCOPED_UNIQUE);
            $table->unique(['event_id', 'active_registration_number'], self::LEGACY_UNIQUE);
            $table->dropColumn('registration_number_scope');
        });
    }

    private function normalizeToGlobalRegistrationNumbers(): void
    {
        foreach (DB::table('events')->orderBy('id')->pluck('id') as $eventId) {
            $next = 0;
            $participants = DB::table('event_participants')
                ->where('event_id', $eventId)
                ->whereNotNull('active_registration_number')
                ->orderBy('checked_in_at')
                ->orderBy('id')
                ->get(['id']);

            foreach ($participants as $participant) {
                $next++;
                DB::table('event_participants')
                    ->where('id', $participant->id)
                    ->update([
                        'registration_number' => $next,
                        'active_registration_number' => $next,
                    ]);
            }
        }
    }
};
