<?php

use App\Enums\ParticipantGender;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('donation_capacity_male')
                ->default(4)
                ->after('donation_capacity');
            $table->unsignedTinyInteger('donation_capacity_female')
                ->default(4)
                ->after('donation_capacity_male');
        });

        // Keep legacy integrations readable while moving the business source of
        // truth to the two independent fields. Existing event capacity is copied
        // to both lanes so an in-progress event never loses available capacity.
        DB::table('event_settings')->update([
            'donation_capacity_male' => DB::raw('CASE WHEN donation_capacity > 0 THEN donation_capacity ELSE 4 END'),
            'donation_capacity_female' => DB::raw('CASE WHEN donation_capacity > 0 THEN donation_capacity ELSE 4 END'),
        ]);

        // A dedicated row per gender is the transaction mutex. Locking this row
        // lets male and female reservations proceed independently; locking the
        // shared event or settings row would incorrectly serialize both lanes.
        Schema::create('event_donation_capacity_lanes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('gender', 10);
            $table->timestamps();
            $table->unique(['event_id', 'gender'], 'event_donation_capacity_lanes_event_gender_unique');
        });

        DB::table('events')->orderBy('id')->chunkById(250, function ($events): void {
            $timestamp = now();
            $rows = [];

            foreach ($events as $event) {
                foreach (ParticipantGender::cases() as $gender) {
                    $rows[] = [
                        'event_id' => $event->id,
                        'gender' => $gender->value,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                }
            }

            DB::table('event_donation_capacity_lanes')->insertOrIgnore($rows);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_donation_capacity_lanes');

        Schema::table('event_settings', function (Blueprint $table): void {
            $table->dropColumn(['donation_capacity_male', 'donation_capacity_female']);
        });
    }
};
