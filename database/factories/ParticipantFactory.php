<?php

namespace Database\Factories;

use App\Enums\ParticipantGender;
use App\Models\Participant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Participant>
 */
class ParticipantFactory extends Factory
{
    protected $model = Participant::class;

    public function definition(): array
    {
        return [
            'nik' => fake()->unique()->numerify('################'),
            'name' => fake()->name(),
            'phone' => fake()->numerify('08##########'),
            'gender' => fake()->randomElement(ParticipantGender::cases()),
            'birth_date' => fake()->optional()->dateTimeBetween('-70 years', '-17 years'),
            'address' => fake()->optional()->address(),
        ];
    }
}
