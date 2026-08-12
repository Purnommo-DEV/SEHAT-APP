<?php

namespace Database\Factories;

use App\Enums\EventStatus;
use App\Enums\ParticipantGender;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        $startsAt = fake()->dateTimeBetween('+1 day', '+6 months');

        return [
            'code' => fake()->unique()->bothify('EVENT-####'),
            'name' => 'Kegiatan '.fake()->word().' '.fake()->word(),
            'description' => fake()->optional()->sentence(),
            'location' => fake()->optional()->city(),
            'starts_at' => $startsAt,
            'ends_at' => (clone $startsAt)->modify('+4 hours'),
            'status' => EventStatus::Draft,
            'created_by' => User::factory(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Event $event): void {
            $event->settings()->firstOrCreate([]);
            $event->donationCapacityLanes()->firstOrCreate([
                'gender' => ParticipantGender::Male->value,
            ]);
            $event->donationCapacityLanes()->firstOrCreate([
                'gender' => ParticipantGender::Female->value,
            ]);
        });
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => EventStatus::Active,
            'active_marker' => 'active',
        ]);
    }
}
