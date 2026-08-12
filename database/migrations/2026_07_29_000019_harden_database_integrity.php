<?php

use App\Enums\EventStatus;
use App\Enums\ParticipantGender;
use App\Enums\ParticipantStatus;
use App\Enums\QueueTicketStatus;
use App\Enums\QueueType;
use App\Enums\ScreeningResult;
use App\Enums\ServicePostType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, array<string, list<string>>>
     */
    private array $checks = [
        'events' => [
            'events_status_check' => [],
            'events_active_marker_check' => ["active_marker IS NULL OR active_marker = 'active'"],
            'events_time_range_check' => ['ends_at IS NULL OR ends_at > starts_at'],
        ],
        'event_settings' => [
            'event_settings_queue_digits_check' => ['general_queue_digits BETWEEN 1 AND 6'],
        ],
        'service_posts' => [
            'service_posts_sequence_check' => ['sequence >= 1'],
            'service_posts_type_check' => [],
        ],
        'participants' => [
            'participants_gender_check' => [],
        ],
        'event_participants' => [
            'event_participants_status_check' => [],
        ],
        'queue_tickets' => [
            'queue_tickets_type_check' => [],
            'queue_tickets_status_check' => [],
            'queue_tickets_number_check' => ['number >= 1'],
            'queue_tickets_timeline_check' => [
                '(served_at IS NULL OR called_at IS NULL OR served_at >= called_at) AND (finished_at IS NULL OR served_at IS NULL OR finished_at >= served_at)',
            ],
        ],
        'health_assessments' => [
            'health_assessments_values_check' => [
                '(blood_sugar IS NULL OR blood_sugar BETWEEN 0 AND 2000) AND (cholesterol IS NULL OR cholesterol BETWEEN 0 AND 2000) AND (uric_acid IS NULL OR uric_acid BETWEEN 0 AND 100)',
            ],
        ],
        'donor_screenings' => [
            'donor_screenings_result_check' => [],
        ],
    ];

    public function up(): void
    {
        Schema::table('service_posts', function (Blueprint $table): void {
            $table->unique(['event_id', 'id'], 'service_posts_event_id_id_unique');
            $table->index(['event_id', 'type', 'is_active'], 'service_posts_event_type_active_index');
            $table->dropIndex('service_posts_event_id_is_active_index');
            $table->dropIndex('service_posts_type_index');
        });
        Schema::table('event_participants', function (Blueprint $table): void {
            $table->unique(['event_id', 'id'], 'event_participants_event_id_id_unique');
            $table->dropIndex('event_participants_status_index');
        });
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex('audit_logs_action_index');
        });
        Schema::table('donor_screenings', function (Blueprint $table): void {
            $table->dropIndex('donor_screenings_result_index');
        });

        Schema::table('event_participants', function (Blueprint $table): void {
            $table->foreign(['event_id', 'current_service_post_id'], 'event_participants_event_current_post_foreign')
                ->references(['event_id', 'id'])
                ->on('service_posts')
                ->restrictOnDelete();
        });
        Schema::table('queue_tickets', function (Blueprint $table): void {
            $table->foreign(['event_id', 'event_participant_id'], 'queue_tickets_event_participant_scope_foreign')
                ->references(['event_id', 'id'])
                ->on('event_participants')
                ->cascadeOnDelete();
            $table->foreign(['event_id', 'service_post_id'], 'queue_tickets_event_post_scope_foreign')
                ->references(['event_id', 'id'])
                ->on('service_posts')
                ->restrictOnDelete();
        });
        Schema::table('health_assessments', function (Blueprint $table): void {
            $table->foreign(['event_id', 'event_participant_id'], 'health_assessments_event_participant_scope_foreign')
                ->references(['event_id', 'id'])
                ->on('event_participants')
                ->cascadeOnDelete();
        });
        Schema::table('donor_screenings', function (Blueprint $table): void {
            $table->foreign(['event_id', 'event_participant_id'], 'donor_screenings_event_participant_scope_foreign')
                ->references(['event_id', 'id'])
                ->on('event_participants')
                ->cascadeOnDelete();
        });

        $this->addDatabaseChecks();
    }

    public function down(): void
    {
        $this->dropDatabaseChecks();

        Schema::table('donor_screenings', function (Blueprint $table): void {
            $table->dropForeign('donor_screenings_event_participant_scope_foreign');
        });
        Schema::table('health_assessments', function (Blueprint $table): void {
            $table->dropForeign('health_assessments_event_participant_scope_foreign');
        });
        Schema::table('queue_tickets', function (Blueprint $table): void {
            $table->dropForeign('queue_tickets_event_participant_scope_foreign');
            $table->dropForeign('queue_tickets_event_post_scope_foreign');
        });
        Schema::table('event_participants', function (Blueprint $table): void {
            $table->dropForeign('event_participants_event_current_post_foreign');
        });

        Schema::table('donor_screenings', function (Blueprint $table): void {
            $table->index('result', 'donor_screenings_result_index');
        });
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->index('action', 'audit_logs_action_index');
        });
        Schema::table('event_participants', function (Blueprint $table): void {
            $table->index('status', 'event_participants_status_index');
            $table->dropUnique('event_participants_event_id_id_unique');
        });
        Schema::table('service_posts', function (Blueprint $table): void {
            $table->index(['event_id', 'is_active'], 'service_posts_event_id_is_active_index');
            $table->index('type', 'service_posts_type_index');
            $table->dropIndex('service_posts_event_type_active_index');
            $table->dropUnique('service_posts_event_id_id_unique');
        });
    }

    private function addDatabaseChecks(): void
    {
        $this->checks['events']['events_status_check'] = [$this->enumCheck('status', EventStatus::cases())];
        $this->checks['service_posts']['service_posts_type_check'] = [$this->enumCheck('type', ServicePostType::cases())];
        $this->checks['participants']['participants_gender_check'] = [$this->enumCheck('gender', ParticipantGender::cases())];
        $this->checks['event_participants']['event_participants_status_check'] = [$this->enumCheck('status', ParticipantStatus::cases())];
        $this->checks['queue_tickets']['queue_tickets_type_check'] = [$this->enumCheck('queue_type', QueueType::cases())];
        $this->checks['queue_tickets']['queue_tickets_status_check'] = [$this->enumCheck('status', QueueTicketStatus::cases())];
        $this->checks['donor_screenings']['donor_screenings_result_check'] = [$this->enumCheck('result', ScreeningResult::cases())];

        if (! in_array(DB::getDriverName(), ['mysql', 'pgsql'], true)) {
            return;
        }

        foreach ($this->checks as $table => $constraints) {
            foreach ($constraints as $name => $expressions) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expressions[0]})");
            }
        }
    }

    private function dropDatabaseChecks(): void
    {
        $driver = DB::getDriverName();

        if (! in_array($driver, ['mysql', 'pgsql'], true)) {
            return;
        }

        foreach ($this->checks as $table => $constraints) {
            foreach (array_keys($constraints) as $name) {
                $clause = "DROP CONSTRAINT {$name}";
                DB::statement("ALTER TABLE {$table} {$clause}");
            }
        }
    }

    /**
     * @param  list<BackedEnum>  $cases
     */
    private function enumCheck(string $column, array $cases): string
    {
        $values = implode(', ', array_map(
            fn (BackedEnum $case): string => DB::getPdo()->quote((string) $case->value),
            $cases,
        ));

        return "{$column} IN ({$values})";
    }
};
