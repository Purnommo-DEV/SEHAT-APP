<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_tickets', function (Blueprint $table): void {
            $table->index(
                ['event_id', 'queue_type', 'status', 'called_at'],
                'queue_tickets_monitor_calling_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('queue_tickets', function (Blueprint $table): void {
            $table->dropIndex('queue_tickets_monitor_calling_index');
        });
    }
};
