<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donor_screenings', function (Blueprint $table): void {
            $table->index(['event_id', 'result'], 'donor_screenings_event_result_index');
        });
    }

    public function down(): void
    {
        Schema::table('donor_screenings', function (Blueprint $table): void {
            $table->dropIndex('donor_screenings_event_result_index');
        });
    }
};
