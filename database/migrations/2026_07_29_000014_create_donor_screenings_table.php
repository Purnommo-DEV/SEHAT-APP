<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donor_screenings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_participant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('result', 20)->index();
            $table->string('reason', 500)->nullable();
            $table->foreignId('screened_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['event_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donor_screenings');
    }
};
