<?php

use App\Enums\ParticipantStatus;
use App\Enums\ServicePostBehavior;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('service_posts', 'behavior')) {
            Schema::table('service_posts', function (Blueprint $table): void {
                $table->string('behavior', 30)
                    ->default(ServicePostBehavior::ConfirmationOnly->value)
                    ->after('type');
                $table->string('queue_prefix', 10)->nullable()->after('behavior');
                $table->unsignedTinyInteger('queue_number_digits')->default(3)->after('queue_prefix');
            });
        }
        if (! $this->hasIndex('service_posts', 'service_posts_event_behavior_active_index')) {
            Schema::table('service_posts', function (Blueprint $table): void {
                $table->index(['event_id', 'behavior', 'is_active'], 'service_posts_event_behavior_active_index');
            });
        }

        $this->transformLegacyBehaviors();

        if (! Schema::hasTable('service_post_user')) {
            Schema::create('service_post_user', function (Blueprint $table): void {
                $table->foreignId('service_post_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->primary(['service_post_id', 'user_id']);
                $table->index(['user_id', 'service_post_id']);
            });
        }

        if (! Schema::hasTable('service_post_submissions')) {
            Schema::create('service_post_submissions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('event_id')->constrained()->cascadeOnDelete();
                $table->foreignId('event_participant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('service_post_id')->constrained()->restrictOnDelete();
                $table->json('payload')->nullable();
                $table->foreignId('completed_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('completed_at');
                $table->timestamps();
                $table->unique(['event_participant_id', 'service_post_id'], 'service_post_submissions_participant_post_unique');
                $table->index(['event_id', 'service_post_id', 'completed_at'], 'service_post_submissions_event_post_completed_index');
            });
        }

        if (! Schema::hasColumn('health_assessments', 'service_post_id')) {
            Schema::table('health_assessments', function (Blueprint $table): void {
                $table->foreignId('service_post_id')->nullable()->after('event_participant_id')->constrained()->restrictOnDelete();
            });
        }
        if (! Schema::hasColumn('donor_screenings', 'service_post_id')) {
            Schema::table('donor_screenings', function (Blueprint $table): void {
                $table->foreignId('service_post_id')->nullable()->after('event_participant_id')->constrained()->restrictOnDelete();
            });
        }

        $this->backfillFormServicePosts();

        $this->dropForeignIfExists('health_assessments', 'health_assessments_event_participant_id_foreign');
        $this->dropForeignIfExists('donor_screenings', 'donor_screenings_event_participant_id_foreign');
        if ($this->hasIndex('health_assessments', 'health_assessments_event_participant_id_unique')) {
            Schema::table('health_assessments', fn (Blueprint $table) => $table->dropUnique('health_assessments_event_participant_id_unique'));
        }
        if (! $this->hasIndex('health_assessments', 'health_assessments_participant_post_unique')) {
            Schema::table('health_assessments', function (Blueprint $table): void {
                $table->unique(['event_participant_id', 'service_post_id'], 'health_assessments_participant_post_unique');
            });
        }
        if (! $this->hasIndex('health_assessments', 'health_assessments_event_post_created_index')) {
            Schema::table('health_assessments', function (Blueprint $table): void {
                $table->index(['event_id', 'service_post_id', 'created_at'], 'health_assessments_event_post_created_index');
            });
        }
        if ($this->hasIndex('donor_screenings', 'donor_screenings_event_participant_id_unique')) {
            Schema::table('donor_screenings', fn (Blueprint $table) => $table->dropUnique('donor_screenings_event_participant_id_unique'));
        }
        if (! $this->hasIndex('donor_screenings', 'donor_screenings_participant_post_unique')) {
            Schema::table('donor_screenings', function (Blueprint $table): void {
                $table->unique(['event_participant_id', 'service_post_id'], 'donor_screenings_participant_post_unique');
            });
        }
        if (! $this->hasIndex('donor_screenings', 'donor_screenings_event_post_created_index')) {
            Schema::table('donor_screenings', function (Blueprint $table): void {
                $table->index(['event_id', 'service_post_id', 'created_at'], 'donor_screenings_event_post_created_index');
            });
        }
        if ($this->hasIndex('queue_tickets', 'queue_tickets_event_id_queue_type_number_unique')) {
            Schema::table('queue_tickets', fn (Blueprint $table) => $table->dropUnique('queue_tickets_event_id_queue_type_number_unique'));
        }
        if (! $this->hasIndex('queue_tickets', 'queue_tickets_event_post_number_unique')) {
            Schema::table('queue_tickets', function (Blueprint $table): void {
                $table->unique(
                    ['event_id', 'service_post_id', 'queue_type', 'number'],
                    'queue_tickets_event_post_number_unique',
                );
            });
        }

        $this->refreshDatabaseChecks();
    }

    public function down(): void
    {
        $this->dropDynamicChecks();

        Schema::table('queue_tickets', function (Blueprint $table): void {
            $table->dropUnique('queue_tickets_event_post_number_unique');
            $table->unique(['event_id', 'queue_type', 'number']);
        });
        Schema::table('donor_screenings', function (Blueprint $table): void {
            $table->dropIndex('donor_screenings_event_post_created_index');
            $table->dropUnique('donor_screenings_participant_post_unique');
            $table->unique('event_participant_id');
            $table->dropConstrainedForeignId('service_post_id');
        });
        Schema::table('health_assessments', function (Blueprint $table): void {
            $table->dropIndex('health_assessments_event_post_created_index');
            $table->dropUnique('health_assessments_participant_post_unique');
            $table->unique('event_participant_id');
            $table->dropConstrainedForeignId('service_post_id');
        });

        Schema::dropIfExists('service_post_submissions');
        Schema::dropIfExists('service_post_user');

        Schema::table('service_posts', function (Blueprint $table): void {
            $table->dropIndex('service_posts_event_behavior_active_index');
            $table->dropColumn(['behavior', 'queue_prefix', 'queue_number_digits']);
        });
    }

    private function transformLegacyBehaviors(): void
    {
        $mapping = [
            'registration' => ServicePostBehavior::ConfirmationOnly->value,
            'health' => ServicePostBehavior::HealthForm->value,
            'screening' => ServicePostBehavior::ScreeningForm->value,
            'donation' => ServicePostBehavior::DonationForm->value,
            'completion' => ServicePostBehavior::ConfirmationOnly->value,
            'custom' => ServicePostBehavior::ConfirmationOnly->value,
        ];

        foreach ($mapping as $type => $behavior) {
            DB::table('service_posts')->where('type', $type)->update(['behavior' => $behavior]);
        }
    }

    private function backfillFormServicePosts(): void
    {
        foreach (DB::table('health_assessments')->select(['id', 'event_id', 'event_participant_id'])->get() as $assessment) {
            $servicePostId = DB::table('queue_tickets')
                ->where('event_participant_id', $assessment->event_participant_id)
                ->whereIn('service_post_id', DB::table('service_posts')
                    ->select('id')
                    ->where('event_id', $assessment->event_id)
                    ->where('behavior', ServicePostBehavior::HealthForm->value))
                ->value('service_post_id');

            DB::table('health_assessments')->where('id', $assessment->id)->update(['service_post_id' => $servicePostId]);
        }

        foreach (DB::table('donor_screenings')->select(['id', 'event_id', 'event_participant_id'])->get() as $screening) {
            $servicePostId = DB::table('service_posts')
                ->where('event_id', $screening->event_id)
                ->where('behavior', ServicePostBehavior::ScreeningForm->value)
                ->orderBy('sequence')
                ->value('id');

            DB::table('donor_screenings')->where('id', $screening->id)->update(['service_post_id' => $servicePostId]);
        }
    }

    private function refreshDatabaseChecks(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'pgsql'], true)) {
            return;
        }

        $this->dropCheck('event_participants', 'event_participants_status_check');
        $statuses = $this->quotedValues(array_column(ParticipantStatus::cases(), 'value'));
        DB::statement("ALTER TABLE event_participants ADD CONSTRAINT event_participants_status_check CHECK (status IN ({$statuses}))");

        $behaviors = $this->quotedValues(array_column(ServicePostBehavior::cases(), 'value'));
        DB::statement("ALTER TABLE service_posts ADD CONSTRAINT service_posts_behavior_check CHECK (behavior IN ({$behaviors}))");
        DB::statement('ALTER TABLE service_posts ADD CONSTRAINT service_posts_queue_digits_check CHECK (queue_number_digits BETWEEN 1 AND 6)');
    }

    private function dropDynamicChecks(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'pgsql'], true)) {
            return;
        }

        $this->dropCheck('service_posts', 'service_posts_behavior_check');
        $this->dropCheck('service_posts', 'service_posts_queue_digits_check');
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

    private function hasIndex(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $row): bool => ($row['name'] ?? null) === $index);
    }

    private function dropForeignIfExists(string $table, string $foreign): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $exists = collect(DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$table, $foreign, 'FOREIGN KEY'],
        ))->isNotEmpty();

        if ($exists) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign($foreign));
        }
    }
};
