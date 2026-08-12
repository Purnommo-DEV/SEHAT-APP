<?php

use App\Enums\ServicePostBehavior;
use App\Enums\ServicePostType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('events')->orderBy('id')->pluck('id') as $eventId) {
            $hasEligibilityPost = DB::table('service_posts')
                ->where('event_id', $eventId)
                ->where('behavior', ServicePostBehavior::ScreeningForm->value)
                ->exists();

            if ($hasEligibilityPost) {
                continue;
            }

            $code = 'cek-kelayakan-donor';
            if (DB::table('service_posts')->where('event_id', $eventId)->where('code', $code)->exists()) {
                $code = "cek-kelayakan-{$eventId}";
            }

            DB::table('service_posts')->insert([
                'event_id' => $eventId,
                'code' => $code,
                'name' => 'Cek Kelayakan Donor',
                'description' => 'Keputusan kelayakan donor sebelum peserta menggunakan kapasitas donor.',
                'type' => ServicePostType::Screening->value,
                'behavior' => ServicePostBehavior::ScreeningForm->value,
                'queue_prefix' => 'K',
                'queue_number_digits' => 3,
                'sequence' => (int) DB::table('service_posts')->where('event_id', $eventId)->max('sequence') + 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Operational event configuration is intentionally retained on rollback
        // so historical participants keep a valid service-post reference.
    }
};
