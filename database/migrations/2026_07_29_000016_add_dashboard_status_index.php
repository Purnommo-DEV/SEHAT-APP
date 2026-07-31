<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_participants', function (Blueprint $table): void {
            $table->index(['event_id', 'status', 'updated_at'], 'event_participants_dashboard_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('event_participants', function (Blueprint $table): void {
            $table->dropIndex('event_participants_dashboard_status_index');
        });
    }
};
