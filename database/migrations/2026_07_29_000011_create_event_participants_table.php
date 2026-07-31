<?php

use App\Enums\ParticipantStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained()->restrictOnDelete();
            $table->foreignId('current_service_post_id')->nullable()->constrained('service_posts')->restrictOnDelete();
            $table->string('status', 30)->default(ParticipantStatus::Registered->value)->index();
            $table->timestamp('checked_in_at')->nullable();
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['event_id', 'participant_id']);
            $table->index(['event_id', 'current_service_post_id', 'status'], 'event_participants_current_post_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_participants');
    }
};
