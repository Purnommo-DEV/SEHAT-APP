<?php

namespace Database\Factories;

use App\Enums\ParticipantServiceStatus;
use App\Enums\ParticipantServiceType;
use App\Models\EventParticipant;
use App\Models\EventParticipantService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventParticipantService>
 */
class EventParticipantServiceFactory extends Factory
{
    protected $model = EventParticipantService::class;

    public function definition(): array
    {
        return [
            'event_participant_id' => EventParticipant::factory(),
            'event_id' => fn (array $attributes): int => (int) EventParticipant::query()
                ->whereKey($attributes['event_participant_id'])
                ->valueOrFail('event_id'),
            'service' => ParticipantServiceType::Donor,
            'status' => ParticipantServiceStatus::WaitingScreening,
            'selected_at' => now(),
        ];
    }
}
