<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('participants', function (Blueprint $table): void {
            $table->string('phone', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('participants')
            ->whereNull('phone')
            ->update(['phone' => '']);

        Schema::table('participants', function (Blueprint $table): void {
            $table->string('phone', 20)->nullable(false)->change();
        });
    }
};
