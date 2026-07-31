<?php

namespace Database\Factories;

use App\Enums\QueueTicketStatus;
use App\Enums\QueueType;
use App\Models\EventParticipant;
use App\Models\QueueTicket;
use App\Models\ServicePost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QueueTicket>
 */
class QueueTicketFactory extends Factory
{
    protected $model = QueueTicket::class;

    public function definition(): array
    {
        return [
            'event_participant_id' => EventParticipant::factory(),
            'event_id' => fn (array $attributes): int => (int) EventParticipant::query()
                ->whereKey($attributes['event_participant_id'])
                ->valueOrFail('event_id'),
            'service_post_id' => fn (array $attributes): ServicePost => ServicePost::factory()
                ->create(['event_id' => $attributes['event_id']]),
            'queue_type' => QueueType::General,
            'number' => fake()->unique()->numberBetween(1, 9999),
            'status' => QueueTicketStatus::Waiting,
        ];
    }
}
