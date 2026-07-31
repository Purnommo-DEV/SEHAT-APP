<?php

use App\Enums\ParticipantServiceStatus;
use App\Enums\ParticipantServiceType;
use App\Enums\ParticipantStatus;
use App\Enums\ScreeningResult;
use App\Enums\ServicePostBehavior;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('event_settings', 'registration_queue_prefix')) {
            Schema::table('event_settings', function (Blueprint $table): void {
                $table->string('registration_queue_prefix', 10)->default('R')->after('event_id');
                $table->unsignedTinyInteger('registration_queue_digits')->default(3)->after('registration_queue_prefix');
            });
        }

        if (! Schema::hasColumn('event_participants', 'registration_number')) {
            Schema::table('event_participants', function (Blueprint $table): void {
                $table->unsignedInteger('registration_number')->nullable()->after('participant_id');
                $table->unique(['event_id', 'registration_number'], 'event_participants_event_registration_number_unique');
            });
        }

        if (! Schema::hasTable('event_participant_services')) {
            Schema::create('event_participant_services', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('event_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('event_participant_id');
                $table->string('service', 30);
                $table->string('status', 30);
                $table->timestamp('selected_at');
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->unique(['event_participant_id', 'service'], 'participant_services_participant_service_unique');
                $table->index(['event_id', 'service', 'status'], 'participant_services_event_service_status_index');
                $table->foreign(['event_id', 'event_participant_id'], 'participant_services_event_participant_scope_foreign')
                    ->references(['event_id', 'id'])
                    ->on('event_participants')
                    ->cascadeOnDelete();
            });
        }

        $this->backfillRegistrationNumbers();
        $this->backfillParticipantServices();
        $this->refreshChecks();
    }

    public function down(): void
    {
        $this->dropChecks();

        Schema::dropIfExists('event_participant_services');

        if (Schema::hasColumn('event_participants', 'registration_number')) {
            Schema::table('event_participants', function (Blueprint $table): void {
                $table->dropUnique('event_participants_event_registration_number_unique');
                $table->dropColumn('registration_number');
            });
        }

        if (Schema::hasColumn('event_settings', 'registration_queue_prefix')) {
            Schema::table('event_settings', function (Blueprint $table): void {
                $table->dropColumn(['registration_queue_prefix', 'registration_queue_digits']);
            });
        }

        $this->restoreParticipantStatusCheck();
    }

    private function backfillRegistrationNumbers(): void
    {
        foreach (DB::table('events')->orderBy('id')->pluck('id') as $eventId) {
            $nextNumber = 0;
            $usedNumbers = [];
            $participants = DB::table('event_participants')
                ->where('event_id', $eventId)
                ->whereNotNull('checked_in_at')
                ->orderBy('checked_in_at')
                ->orderBy('id')
                ->get(['id']);

            foreach ($participants as $participant) {
                $candidate = DB::table('queue_tickets')
                    ->where('event_participant_id', $participant->id)
                    ->orderBy('id')
                    ->value('number');
                $number = is_numeric($candidate) ? (int) $candidate : null;

                if ($number === null || isset($usedNumbers[$number])) {
                    do {
                        $nextNumber++;
                    } while (isset($usedNumbers[$nextNumber]));
                    $number = $nextNumber;
                }

                $usedNumbers[$number] = true;
                $nextNumber = max($nextNumber, $number);
                DB::table('event_participants')
                    ->where('id', $participant->id)
                    ->update(['registration_number' => $number]);
            }
        }
    }

    private function backfillParticipantServices(): void
    {
        $behaviorByPost = DB::table('service_posts')->pluck('behavior', 'id')->all();

        foreach (DB::table('event_participants')->orderBy('id')->get() as $participant) {
            $ticketBehaviors = DB::table('queue_tickets')
                ->where('event_participant_id', $participant->id)
                ->pluck('service_post_id')
                ->map(fn (int $postId): ?string => $behaviorByPost[$postId] ?? null)
                ->filter()
                ->all();
            $currentBehavior = $participant->current_service_post_id === null
                ? null
                : ($behaviorByPost[$participant->current_service_post_id] ?? null);
            $status = (string) $participant->status;
            $hasScreening = DB::table('donor_screenings')
                ->where('event_participant_id', $participant->id)
                ->exists();
            $hasHealthAssessment = DB::table('health_assessments')
                ->where('event_participant_id', $participant->id)
                ->exists();
            $hasDonorService = $hasScreening
                || in_array(ServicePostBehavior::ScreeningForm->value, $ticketBehaviors, true)
                || in_array(ServicePostBehavior::DonationForm->value, $ticketBehaviors, true)
                || in_array($currentBehavior, [ServicePostBehavior::ScreeningForm->value, ServicePostBehavior::DonationForm->value], true)
                || in_array($status, [
                    ParticipantStatus::WaitingScreening->value,
                    ParticipantStatus::WaitingDonor->value,
                    ParticipantStatus::DonationInProgress->value,
                    ParticipantStatus::DonationCompleted->value,
                    ParticipantStatus::NotEligible->value,
                ], true);
            $hasHealthService = $hasHealthAssessment
                || in_array(ServicePostBehavior::HealthForm->value, $ticketBehaviors, true)
                || $currentBehavior === ServicePostBehavior::HealthForm->value
                || in_array($status, [
                    ParticipantStatus::WaitingHealth->value,
                    ParticipantStatus::HealthInProgress->value,
                    ParticipantStatus::HealthCheckCompleted->value,
                ], true);

            if ($hasDonorService) {
                $screeningResult = DB::table('donor_screenings')
                    ->where('event_participant_id', $participant->id)
                    ->value('result');
                $donorStatus = match (true) {
                    $screeningResult === ScreeningResult::NotEligible->value => ParticipantServiceStatus::NotEligible,
                    $status === ParticipantStatus::DonationCompleted->value => ParticipantServiceStatus::Completed,
                    $status === ParticipantStatus::DonationInProgress->value => ParticipantServiceStatus::DonationInProgress,
                    $status === ParticipantStatus::WaitingDonor->value || $screeningResult === ScreeningResult::Eligible->value => ParticipantServiceStatus::WaitingDonation,
                    default => ParticipantServiceStatus::WaitingScreening,
                };
                $this->insertService(
                    (int) $participant->event_id,
                    (int) $participant->id,
                    $participant->checked_in_at,
                    $participant->created_at,
                    $participant->updated_at,
                    ParticipantServiceType::Donor,
                    $donorStatus,
                );
            }

            if ($hasHealthService) {
                $healthStatus = match (true) {
                    $hasHealthAssessment || $status === ParticipantStatus::HealthCheckCompleted->value => ParticipantServiceStatus::Completed,
                    $status === ParticipantStatus::HealthInProgress->value => ParticipantServiceStatus::HealthCheckInProgress,
                    $hasDonorService && ! in_array($status, [
                        ParticipantStatus::WaitingHealth->value,
                        ParticipantStatus::HealthInProgress->value,
                        ParticipantStatus::HealthCheckCompleted->value,
                    ], true) => ParticipantServiceStatus::Pending,
                    default => ParticipantServiceStatus::WaitingHealthCheck,
                };
                $this->insertService(
                    (int) $participant->event_id,
                    (int) $participant->id,
                    $participant->checked_in_at,
                    $participant->created_at,
                    $participant->updated_at,
                    ParticipantServiceType::HealthCheck,
                    $healthStatus,
                );
            }
        }
    }

    private function insertService(
        int $eventId,
        int $eventParticipantId,
        ?string $checkedInAt,
        ?string $createdAt,
        ?string $updatedAt,
        ParticipantServiceType $service,
        ParticipantServiceStatus $status,
    ): void {
        DB::table('event_participant_services')->insertOrIgnore([
            'event_id' => $eventId,
            'event_participant_id' => $eventParticipantId,
            'service' => $service->value,
            'status' => $status->value,
            'selected_at' => $checkedInAt ?? $createdAt ?? now(),
            'started_at' => null,
            'completed_at' => $status->isTerminal() ? ($updatedAt ?? now()) : null,
            'created_at' => $createdAt ?? now(),
            'updated_at' => $updatedAt ?? now(),
        ]);
    }

    private function refreshChecks(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'pgsql'], true)) {
            return;
        }

        $this->dropCheck('event_participants', 'event_participants_status_check');
        $participantStatuses = $this->quotedValues(array_column(ParticipantStatus::cases(), 'value'));
        DB::statement("ALTER TABLE event_participants ADD CONSTRAINT event_participants_status_check CHECK (status IN ({$participantStatuses}))");

        $serviceTypes = $this->quotedValues(array_column(ParticipantServiceType::cases(), 'value'));
        $serviceStatuses = $this->quotedValues(array_column(ParticipantServiceStatus::cases(), 'value'));
        DB::statement("ALTER TABLE event_participant_services ADD CONSTRAINT participant_services_service_check CHECK (service IN ({$serviceTypes}))");
        DB::statement("ALTER TABLE event_participant_services ADD CONSTRAINT participant_services_status_check CHECK (status IN ({$serviceStatuses}))");
        DB::statement('ALTER TABLE event_settings ADD CONSTRAINT event_settings_registration_digits_check CHECK (registration_queue_digits BETWEEN 1 AND 6)');
    }

    private function dropChecks(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'pgsql'], true)) {
            return;
        }

        $this->dropCheck('event_participant_services', 'participant_services_service_check');
        $this->dropCheck('event_participant_services', 'participant_services_status_check');
        $this->dropCheck('event_settings', 'event_settings_registration_digits_check');
    }

    private function restoreParticipantStatusCheck(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'pgsql'], true)) {
            return;
        }

        $this->dropCheck('event_participants', 'event_participants_status_check');
        $statuses = $this->quotedValues(array_column(ParticipantStatus::cases(), 'value'));
        DB::statement("ALTER TABLE event_participants ADD CONSTRAINT event_participants_status_check CHECK (status IN ({$statuses}))");
    }

    private function dropCheck(string $table, string $name): void
    {
        $clause = DB::getDriverName() === 'mysql' ? "DROP CHECK {$name}" : "DROP CONSTRAINT {$name}";
        DB::statement("ALTER TABLE {$table} {$clause}");
    }

    /**
     * @param  list<string>  $values
     */
    private function quotedValues(array $values): string
    {
        return implode(', ', array_map(
            fn (string $value): string => DB::getPdo()->quote($value),
            $values,
        ));
    }
};
