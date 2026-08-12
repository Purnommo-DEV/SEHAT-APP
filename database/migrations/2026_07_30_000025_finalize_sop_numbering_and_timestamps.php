<?php

use App\Enums\DonorNumberMode;
use App\Enums\ParticipantServiceStatus;
use App\Enums\ParticipantServiceType;
use App\Enums\ParticipantStatus;
use App\Enums\QueueTicketStatus;
use App\Enums\QueueType;
use App\Enums\RegistrationNumberFormat;
use App\Enums\ServicePostBehavior;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_QUEUE_NUMBER_INDEX = 'queue_tickets_event_post_number_unique';

    private const QUEUE_NUMBER_UNIQUE = 'queue_tickets_event_scope_active_number_unique';

    private const OLD_REGISTRATION_UNIQUE = 'event_participants_event_registration_number_unique';

    private const REGISTRATION_UNIQUE = 'event_participants_event_active_registration_unique';

    public function up(): void
    {
        Schema::table('event_settings', function (Blueprint $table): void {
            $table->string('registration_number_format', 30)
                ->default(RegistrationNumberFormat::GenderPrefix->value)
                ->after('event_id');
            $table->string('registration_male_prefix', 10)->default('L')->after('registration_queue_prefix');
            $table->string('registration_female_prefix', 10)->default('P')->after('registration_male_prefix');
            $table->string('donor_number_mode', 30)
                ->default(DonorNumberMode::Global->value)
                ->after('registration_queue_digits');
            $table->string('donor_queue_prefix', 10)->default('D')->after('donor_number_mode');
            $table->unsignedTinyInteger('donor_queue_digits')->default(3)->after('donor_queue_prefix');
        });

        // Existing events keep their previous visible numbering contract.
        // Rows created after this migration use the new SOP defaults above.
        DB::table('event_settings')->update([
            'registration_number_format' => RegistrationNumberFormat::Uniform->value,
            'donor_number_mode' => DonorNumberMode::Global->value,
        ]);
        $genderSeparatedEvents = DB::table('queue_tickets')
            ->join('service_posts', 'service_posts.id', '=', 'queue_tickets.service_post_id')
            ->where('service_posts.behavior', ServicePostBehavior::DonationForm->value)
            ->whereIn('queue_tickets.queue_type', [
                QueueType::MaleDonor->value,
                QueueType::FemaleDonor->value,
            ])
            ->distinct()
            ->pluck('queue_tickets.event_id');

        foreach ($genderSeparatedEvents->chunk(500) as $eventIds) {
            DB::table('event_settings')
                ->whereIn('event_id', $eventIds)
                ->update(['donor_number_mode' => DonorNumberMode::GenderSeparated->value]);
        }

        Schema::table('event_participants', function (Blueprint $table): void {
            $table->unsignedInteger('active_registration_number')->nullable()->after('registration_number');
            $table->timestamp('completed_at')->nullable()->after('checked_in_by');
            $table->timestamp('cancelled_at')->nullable()->after('completed_at');
            $table->foreignId('cancelled_by')
                ->nullable()
                ->after('cancelled_at')
                ->constrained('users')
                ->nullOnDelete();
        });
        DB::table('event_participants')
            ->whereNotNull('registration_number')
            ->update(['active_registration_number' => DB::raw('registration_number')]);
        $this->replaceRegistrationNumberConstraint();

        Schema::table('event_participant_services', function (Blueprint $table): void {
            $table->timestamp('eligibility_started_at')->nullable()->after('selected_at');
            $table->timestamp('eligibility_completed_at')->nullable()->after('eligibility_started_at');
        });

        Schema::table('queue_tickets', function (Blueprint $table): void {
            $table->string('number_scope', 64)->nullable()->after('number');
            $table->unsignedInteger('active_number')->nullable()->after('number_scope');
            $table->timestamp('cancelled_at')->nullable()->after('finished_at');
            $table->foreignId('cancelled_by')
                ->nullable()
                ->after('cancelled_at')
                ->constrained('users')
                ->nullOnDelete();
        });

        $this->replaceQueueNumberConstraint();
        $this->backfillOperationalTimestamps();
        $this->refreshDatabaseChecks();
    }

    public function down(): void
    {
        $this->dropFinalChecks();

        DB::table('queue_tickets')
            ->where('status', QueueTicketStatus::Cancelled->value)
            ->update(['status' => QueueTicketStatus::Skipped->value]);
        DB::table('queue_tickets')
            ->where('queue_type', QueueType::DonorGlobal->value)
            ->update(['queue_type' => QueueType::General->value]);
        DB::table('event_participant_services')
            ->where('status', ParticipantServiceStatus::Cancelled->value)
            ->update(['status' => ParticipantServiceStatus::Pending->value]);

        $this->makeLegacyQueueNumbersUnique();
        $this->makeLegacyRegistrationNumbersUnique();

        if ($this->hasIndex('queue_tickets', self::QUEUE_NUMBER_UNIQUE)) {
            Schema::table('queue_tickets', function (Blueprint $table): void {
                $table->dropUnique(self::QUEUE_NUMBER_UNIQUE);
            });
        }

        if (! $this->hasIndex('queue_tickets', self::OLD_QUEUE_NUMBER_INDEX)) {
            Schema::table('queue_tickets', function (Blueprint $table): void {
                $table->unique(
                    ['event_id', 'service_post_id', 'queue_type', 'number'],
                    self::OLD_QUEUE_NUMBER_INDEX,
                );
            });
        }

        Schema::table('queue_tickets', function (Blueprint $table): void {
            $table->dropForeign(['cancelled_by']);
            $table->dropColumn([
                'number_scope',
                'active_number',
                'cancelled_at',
                'cancelled_by',
            ]);
        });
        Schema::table('event_participant_services', function (Blueprint $table): void {
            $table->dropColumn(['eligibility_started_at', 'eligibility_completed_at']);
        });
        Schema::table('event_participants', function (Blueprint $table): void {
            $table->dropForeign(['cancelled_by']);
            $table->dropUnique(self::REGISTRATION_UNIQUE);
            $table->dropColumn([
                'active_registration_number',
                'completed_at',
                'cancelled_at',
                'cancelled_by',
            ]);
            $table->unique(['event_id', 'registration_number'], self::OLD_REGISTRATION_UNIQUE);
        });
        Schema::table('event_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'registration_number_format',
                'registration_male_prefix',
                'registration_female_prefix',
                'donor_number_mode',
                'donor_queue_prefix',
                'donor_queue_digits',
            ]);
        });
    }

    private function replaceRegistrationNumberConstraint(): void
    {
        if ($this->hasIndex('event_participants', self::OLD_REGISTRATION_UNIQUE)) {
            Schema::table('event_participants', function (Blueprint $table): void {
                $table->dropUnique(self::OLD_REGISTRATION_UNIQUE);
            });
        }

        Schema::table('event_participants', function (Blueprint $table): void {
            $table->unique(
                ['event_id', 'active_registration_number'],
                self::REGISTRATION_UNIQUE,
            );
        });
    }

    private function replaceQueueNumberConstraint(): void
    {
        if ($this->hasIndex('queue_tickets', self::OLD_QUEUE_NUMBER_INDEX)) {
            Schema::table('queue_tickets', function (Blueprint $table): void {
                $table->dropUnique(self::OLD_QUEUE_NUMBER_INDEX);
            });
        }

        $donationPostIds = DB::table('service_posts')
            ->where('behavior', ServicePostBehavior::DonationForm->value)
            ->pluck('id')
            ->mapWithKeys(fn (int $id): array => [$id => true])
            ->all();
        $donorModes = DB::table('event_settings')->pluck('donor_number_mode', 'event_id');
        $participantGenders = DB::table('event_participants')
            ->join('participants', 'participants.id', '=', 'event_participants.participant_id')
            ->pluck('participants.gender', 'event_participants.id');
        $usedNumbers = [];

        foreach (DB::table('queue_tickets')->orderBy('id')->get([
            'id',
            'event_id',
            'event_participant_id',
            'service_post_id',
            'queue_type',
            'number',
            'status',
        ]) as $ticket) {
            $queueType = (string) $ticket->queue_type;

            if ($queueType === QueueType::General->value
                && isset($donationPostIds[(int) $ticket->service_post_id])
            ) {
                $queueType = $donorModes->get($ticket->event_id) === DonorNumberMode::GenderSeparated->value
                    ? ($participantGenders->get($ticket->event_participant_id) === 'female'
                        ? QueueType::FemaleDonor->value
                        : QueueType::MaleDonor->value)
                    : QueueType::DonorGlobal->value;
            }

            $scope = in_array($queueType, [
                QueueType::DonorGlobal->value,
                QueueType::MaleDonor->value,
                QueueType::FemaleDonor->value,
            ], true)
                ? "lane:{$queueType}"
                : "post:{$ticket->service_post_id}";
            $number = (int) $ticket->number;
            $active = $ticket->status !== QueueTicketStatus::Cancelled->value;

            if ($active) {
                $key = "{$ticket->event_id}:{$scope}";
                $usedNumbers[$key] ??= [];

                if (isset($usedNumbers[$key][$number])) {
                    $number = 1;

                    while (isset($usedNumbers[$key][$number])) {
                        $number++;
                    }
                }

                $usedNumbers[$key][$number] = true;
            }

            DB::table('queue_tickets')->where('id', $ticket->id)->update([
                'queue_type' => $queueType,
                'number' => $number,
                'number_scope' => $scope,
                'active_number' => $active ? $number : null,
            ]);
        }

        Schema::table('queue_tickets', function (Blueprint $table): void {
            $table->unique(
                ['event_id', 'number_scope', 'active_number'],
                self::QUEUE_NUMBER_UNIQUE,
            );
        });
    }

    private function backfillOperationalTimestamps(): void
    {
        DB::table('event_participants')
            ->whereIn('status', [
                ParticipantStatus::Finished->value,
                ParticipantStatus::DonationCompleted->value,
                ParticipantStatus::HealthCheckCompleted->value,
                ParticipantStatus::NotEligible->value,
            ])
            ->whereNull('completed_at')
            ->update(['completed_at' => DB::raw('updated_at')]);

        $donationPostIds = DB::table('service_posts')
            ->where('behavior', ServicePostBehavior::DonationForm->value)
            ->pluck('id');
        $screeningPostIds = DB::table('service_posts')
            ->where('behavior', ServicePostBehavior::ScreeningForm->value)
            ->pluck('id');

        foreach (DB::table('event_participant_services')
            ->where('service', ParticipantServiceType::Donor->value)
            ->orderBy('id')
            ->get() as $service) {
            $screening = DB::table('donor_screenings')
                ->where('event_participant_id', $service->event_participant_id)
                ->oldest('id')
                ->first(['created_at', 'updated_at']);
            $donationTicket = DB::table('queue_tickets')
                ->where('event_participant_id', $service->event_participant_id)
                ->whereIn('service_post_id', $donationPostIds)
                ->oldest('id')
                ->first(['served_at', 'finished_at', 'created_at']);
            $screeningTicket = DB::table('queue_tickets')
                ->where('event_participant_id', $service->event_participant_id)
                ->whereIn('service_post_id', $screeningPostIds)
                ->oldest('id')
                ->first(['served_at']);
            $updates = [];

            if ($screening !== null) {
                $updates['eligibility_started_at'] = $screeningTicket?->served_at;
                $updates['eligibility_completed_at'] = $screening->updated_at ?? $screening->created_at;
            } elseif (in_array($service->status, [
                ParticipantServiceStatus::ScreeningInProgress->value,
                ParticipantServiceStatus::WaitingDonation->value,
                ParticipantServiceStatus::DonationInProgress->value,
                ParticipantServiceStatus::Completed->value,
                ParticipantServiceStatus::NotEligible->value,
            ], true)) {
                $updates['eligibility_started_at'] = $screeningTicket?->served_at;
            }

            if ($donationTicket !== null) {
                $updates['started_at'] = $donationTicket->served_at ?? $donationTicket->created_at;

                if ($donationTicket->finished_at !== null) {
                    $updates['completed_at'] = $donationTicket->finished_at;
                }
            }

            if ($updates !== []) {
                DB::table('event_participant_services')->where('id', $service->id)->update($updates);
            }
        }
    }

    private function refreshDatabaseChecks(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'pgsql'], true)) {
            return;
        }

        $this->dropCheck('queue_tickets', 'queue_tickets_type_check');
        $this->dropCheck('queue_tickets', 'queue_tickets_status_check');
        $this->dropCheck('event_participant_services', 'participant_services_status_check');
        $this->dropCheck('event_participants', 'event_participants_status_check');

        DB::statement(sprintf(
            'ALTER TABLE queue_tickets ADD CONSTRAINT queue_tickets_type_check CHECK (queue_type IN (%s))',
            $this->quotedValues(array_column(QueueType::cases(), 'value')),
        ));
        DB::statement(sprintf(
            'ALTER TABLE queue_tickets ADD CONSTRAINT queue_tickets_status_check CHECK (status IN (%s))',
            $this->quotedValues(array_column(QueueTicketStatus::cases(), 'value')),
        ));
        DB::statement(sprintf(
            'ALTER TABLE event_participant_services ADD CONSTRAINT participant_services_status_check CHECK (status IN (%s))',
            $this->quotedValues(array_column(ParticipantServiceStatus::cases(), 'value')),
        ));
        DB::statement(sprintf(
            'ALTER TABLE event_participants ADD CONSTRAINT event_participants_status_check CHECK (status IN (%s))',
            $this->quotedValues(array_column(ParticipantStatus::cases(), 'value')),
        ));
        DB::statement(sprintf(
            'ALTER TABLE event_settings ADD CONSTRAINT event_settings_registration_format_check CHECK (registration_number_format IN (%s))',
            $this->quotedValues(array_column(RegistrationNumberFormat::cases(), 'value')),
        ));
        DB::statement(sprintf(
            'ALTER TABLE event_settings ADD CONSTRAINT event_settings_donor_mode_check CHECK (donor_number_mode IN (%s))',
            $this->quotedValues(array_column(DonorNumberMode::cases(), 'value')),
        ));
        DB::statement(
            'ALTER TABLE event_settings ADD CONSTRAINT event_settings_donor_digits_check CHECK (donor_queue_digits BETWEEN 1 AND 6)',
        );
        DB::statement(
            'ALTER TABLE queue_tickets ADD CONSTRAINT queue_tickets_number_scope_check CHECK (number_scope IS NOT NULL AND (active_number IS NULL OR active_number = number))',
        );
        DB::statement(
            'ALTER TABLE event_participants ADD CONSTRAINT event_participants_active_registration_check CHECK (active_registration_number IS NULL OR active_registration_number = registration_number)',
        );
    }

    /**
     * A cancelled number may have been reused while this migration was active.
     * The legacy schema cannot represent duplicate historical numbers, so rows
     * without an active claim are deterministically moved above the old maximum
     * before the legacy unique index is restored.
     */
    private function makeLegacyQueueNumbersUnique(): void
    {
        $duplicates = DB::table('queue_tickets')
            ->select([
                'event_id',
                'service_post_id',
                'queue_type',
                'number',
                DB::raw('COUNT(*) AS aggregate'),
            ])
            ->groupBy('event_id', 'service_post_id', 'queue_type', 'number')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $scope = DB::table('queue_tickets')
                ->where('event_id', $duplicate->event_id)
                ->where('service_post_id', $duplicate->service_post_id)
                ->where('queue_type', $duplicate->queue_type);
            $nextNumber = (int) (clone $scope)->max('number');
            $rows = (clone $scope)
                ->where('number', $duplicate->number)
                ->orderByRaw('CASE WHEN active_number IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc('id')
                ->get(['id']);

            foreach ($rows->skip(1) as $row) {
                $nextNumber++;
                DB::table('queue_tickets')
                    ->where('id', $row->id)
                    ->update(['number' => $nextNumber]);
            }
        }
    }

    private function makeLegacyRegistrationNumbersUnique(): void
    {
        $duplicates = DB::table('event_participants')
            ->select([
                'event_id',
                'registration_number',
                DB::raw('COUNT(*) AS aggregate'),
            ])
            ->whereNotNull('registration_number')
            ->groupBy('event_id', 'registration_number')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $eventParticipants = DB::table('event_participants')
                ->where('event_id', $duplicate->event_id);
            $nextNumber = (int) (clone $eventParticipants)->max('registration_number');
            $rows = (clone $eventParticipants)
                ->where('registration_number', $duplicate->registration_number)
                ->orderByRaw('CASE WHEN active_registration_number IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc('id')
                ->get(['id']);

            foreach ($rows->skip(1) as $row) {
                $nextNumber++;
                DB::table('event_participants')
                    ->where('id', $row->id)
                    ->update(['registration_number' => $nextNumber]);
            }
        }
    }

    private function dropFinalChecks(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'pgsql'], true)) {
            return;
        }

        $this->dropCheck('event_settings', 'event_settings_registration_format_check');
        $this->dropCheck('event_settings', 'event_settings_donor_mode_check');
        $this->dropCheck('event_settings', 'event_settings_donor_digits_check');
        $this->dropCheck('queue_tickets', 'queue_tickets_number_scope_check');
        $this->dropCheck('event_participants', 'event_participants_active_registration_check');
    }

    private function dropCheck(string $table, string $name): void
    {
        $clause = "DROP CONSTRAINT {$name}";
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

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => ($index['name'] ?? null) === $name);
    }
};
