<?php

use App\Enums\ParticipantGender;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('participants', function (Blueprint $table): void {
            $table->id();
            $table->string('nik', 16)->nullable()->unique();
            $table->string('name')->index();
            $table->string('phone', 20)->index();
            $table->string('gender', 10)->default(ParticipantGender::Male->value);
            $table->date('birth_date')->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participants');
    }
};
