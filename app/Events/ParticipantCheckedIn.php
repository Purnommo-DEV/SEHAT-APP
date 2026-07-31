<?php

namespace App\Events;

use App\Events\Concerns\QueuesBroadcasts;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ParticipantCheckedIn implements ShouldBroadcast
{
    use Dispatchable, QueuesBroadcasts, SerializesModels;

    public function __construct(
        public readonly int $eventId,
        public readonly int $eventParticipantId,
        public readonly int $queueTicketId,
        public readonly string $queueNumber,
        public readonly string $registrationNumber,
        /** @var list<string> */
        public readonly array $services,
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
        return 'participant.checked-in';
    }

    /**
     * @return array<string, int|string|list<string>>
     */
    public function broadcastWith(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_participant_id' => $this->eventParticipantId,
            'queue_ticket_id' => $this->queueTicketId,
            'queue_number' => $this->queueNumber,
            'registration_number' => $this->registrationNumber,
            'services' => $this->services,
        ];
    }
}
