<?php

namespace Database\Factories;

use App\Enums\ParticipantStatus;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Participant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventParticipant>
 */
class EventParticipantFactory extends Factory
{
    protected $model = EventParticipant::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'participant_id' => Participant::factory(),
            'status' => ParticipantStatus::Registered,
        ];
    }
}
