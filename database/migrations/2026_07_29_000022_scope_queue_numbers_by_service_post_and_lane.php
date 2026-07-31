<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'queue_tickets_event_post_number_unique';

    public function up(): void
    {
        $indexes = collect(Schema::getIndexes('queue_tickets'));
        $index = $indexes->firstWhere('name', self::INDEX);

        if ($index !== null
            && ($index['columns'] ?? []) === ['event_id', 'service_post_id', 'number']
        ) {
            Schema::table('queue_tickets', function (Blueprint $table): void {
                $table->dropUnique(self::INDEX);
            });
        }

        $indexes = collect(Schema::getIndexes('queue_tickets'));

        if (! $indexes->contains(fn (array $row): bool => ($row['name'] ?? null) === self::INDEX)) {
            Schema::table('queue_tickets', function (Blueprint $table): void {
                $table->unique(
                    ['event_id', 'service_post_id', 'queue_type', 'number'],
                    self::INDEX,
                );
            });
        }
    }

    public function down(): void
    {
        $indexes = collect(Schema::getIndexes('queue_tickets'));

        if ($indexes->contains(fn (array $row): bool => ($row['name'] ?? null) === self::INDEX)) {
            Schema::table('queue_tickets', function (Blueprint $table): void {
                $table->dropUnique(self::INDEX);
            });
        }

        Schema::table('queue_tickets', function (Blueprint $table): void {
            $table->unique(
                ['event_id', 'service_post_id', 'number'],
                self::INDEX,
            );
        });
    }
};
