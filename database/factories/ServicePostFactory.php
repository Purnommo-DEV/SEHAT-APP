<?php

namespace Database\Factories;

use App\Enums\ServicePostBehavior;
use App\Enums\ServicePostType;
use App\Models\Event;
use App\Models\ServicePost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServicePost>
 */
class ServicePostFactory extends Factory
{
    protected $model = ServicePost::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'code' => fake()->unique()->bothify('pos-####'),
            'name' => 'Pos '.fake()->word().' '.fake()->word(),
            'description' => fake()->optional()->sentence(),
            'type' => ServicePostType::Custom,
            'behavior' => fn (array $attributes): ServicePostBehavior => match ($attributes['type'] ?? ServicePostType::Custom) {
                ServicePostType::Health => ServicePostBehavior::HealthForm,
                ServicePostType::Screening => ServicePostBehavior::ScreeningForm,
                ServicePostType::Donation => ServicePostBehavior::DonationForm,
                default => ServicePostBehavior::ConfirmationOnly,
            },
            'queue_prefix' => null,
            'queue_number_digits' => 3,
            'sequence' => 1,
            'is_active' => true,
        ];
    }
}
