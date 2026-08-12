<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ORDER_UNIQUE = 'event_participants_event_registration_order_unique';

    private const OPERATIONAL_ORDER_INDEX = 'event_participants_operational_order_index';

    public function up(): void
    {
        Schema::table('event_participants', function (Blueprint $table): void {
            $table->unsignedInteger('registration_order')
                ->nullable()
                ->after('checked_in_at');
        });

        foreach (DB::table('events')->orderBy('id')->pluck('id') as $eventId) {
            $order = 0;

            foreach (DB::table('event_participants')
                ->where('event_id', $eventId)
                ->whereNotNull('checked_in_at')
                ->orderBy('checked_in_at')
                ->orderBy('id')
                ->select(['id'])
                ->cursor() as $participant) {
                $order++;

                DB::table('event_participants')
                    ->where('id', $participant->id)
                    ->update(['registration_order' => $order]);
            }
        }

        Schema::table('event_participants', function (Blueprint $table): void {
            $table->unique(['event_id', 'registration_order'], self::ORDER_UNIQUE);
            $table->index(
                ['event_id', 'status', 'registration_order', 'checked_in_at'],
                self::OPERATIONAL_ORDER_INDEX,
            );
        });
    }

    public function down(): void
    {
        Schema::table('event_participants', function (Blueprint $table): void {
            $table->dropIndex(self::OPERATIONAL_ORDER_INDEX);
            $table->dropUnique(self::ORDER_UNIQUE);
            $table->dropColumn('registration_order');
        });
    }
};
