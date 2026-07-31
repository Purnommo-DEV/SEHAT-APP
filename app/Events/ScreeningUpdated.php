<?php

namespace App\Events;

use App\Enums\ScreeningResult;
use App\Events\Concerns\QueuesBroadcasts;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ScreeningUpdated implements ShouldBroadcast
{
    use Dispatchable, QueuesBroadcasts, SerializesModels;

    public function __construct(
        public readonly int $eventId,
        public readonly int $eventParticipantId,
        public readonly ScreeningResult $result,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("events.{$this->eventId}")];
    }

    public function broadcastAs(): string
    {
        return 'screening.updated';
    }

    /**
     * @return array<string, int|string>
     */
    public function broadcastWith(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_participant_id' => $this->eventParticipantId,
            'result' => $this->result->value,
        ];
    }
}
