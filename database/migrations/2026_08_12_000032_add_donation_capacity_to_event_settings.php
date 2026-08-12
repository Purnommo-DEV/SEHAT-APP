<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('donation_capacity')->default(4)->after('donor_queue_digits');
        });
    }

    public function down(): void
    {
        Schema::table('event_settings', function (Blueprint $table): void {
            $table->dropColumn('donation_capacity');
        });
    }
};
