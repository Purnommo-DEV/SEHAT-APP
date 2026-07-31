<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_participant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('blood_pressure', 15)->nullable();
            $table->decimal('blood_sugar', 8, 2)->nullable();
            $table->decimal('cholesterol', 8, 2)->nullable();
            $table->decimal('uric_acid', 8, 2)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['event_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_assessments');
    }
};
