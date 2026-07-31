<?php

use App\Enums\QueueTicketStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_participant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_post_id')->constrained('service_posts')->restrictOnDelete();
            $table->string('queue_type', 20);
            $table->unsignedInteger('number');
            $table->string('status', 20)->default(QueueTicketStatus::Waiting->value);
            $table->timestamp('called_at')->nullable();
            $table->foreignId('called_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('served_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'queue_type', 'number']);
            $table->unique(['event_participant_id', 'service_post_id']);
            $table->index(['event_id', 'service_post_id', 'status', 'number'], 'queue_tickets_post_status_number_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_tickets');
    }
};
