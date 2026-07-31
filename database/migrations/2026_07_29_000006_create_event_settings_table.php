<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('general_queue_digits')->default(3);
            $table->string('male_donor_queue_prefix', 5)->default('L');
            $table->string('female_donor_queue_prefix', 5)->default('P');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_settings');
    }
};
