<?php

namespace Database\Factories;

use App\Models\EventParticipant;
use App\Models\HealthAssessment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HealthAssessment>
 */
class HealthAssessmentFactory extends Factory
{
    protected $model = HealthAssessment::class;

    public function definition(): array
    {
        return [
            'event_participant_id' => EventParticipant::factory(),
            'event_id' => fn (array $attributes): int => (int) EventParticipant::query()
                ->whereKey($attributes['event_participant_id'])
                ->valueOrFail('event_id'),
            'blood_pressure' => fake()->numberBetween(100, 140).'/'.fake()->numberBetween(60, 95),
            'blood_sugar' => fake()->randomFloat(2, 70, 180),
            'cholesterol' => fake()->randomFloat(2, 140, 260),
            'uric_acid' => fake()->randomFloat(2, 3, 10),
            'notes' => fake()->optional()->sentence(),
            'created_by' => User::factory(),
        ];
    }
}
