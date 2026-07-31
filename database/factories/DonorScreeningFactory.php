<?php

namespace Database\Factories;

use App\Enums\ScreeningResult;
use App\Models\DonorScreening;
use App\Models\EventParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DonorScreening>
 */
class DonorScreeningFactory extends Factory
{
    protected $model = DonorScreening::class;

    public function definition(): array
    {
        return [
            'event_participant_id' => EventParticipant::factory(),
            'event_id' => fn (array $attributes): int => (int) EventParticipant::query()
                ->whereKey($attributes['event_participant_id'])
                ->valueOrFail('event_id'),
            'result' => ScreeningResult::Eligible,
            'reason' => null,
            'screened_by' => User::factory(),
        ];
    }
}
