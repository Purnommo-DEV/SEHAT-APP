<?php

namespace App\Events;

use App\Events\Concerns\QueuesBroadcasts;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

abstract class ParticipantWorkflowEvent implements ShouldBroadcast
{
    use Dispatchable, QueuesBroadcasts, SerializesModels;

    public function __construct(
        public int $eventId,
        public int $eventParticipantId,
        public ?int $queueTicketId = null,
        public ?int $nextServicePostId = null,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("events.{$this->eventId}")];
    }

    /**
     * @return array<string, int|null>
     */
    public function broadcastWith(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_participant_id' => $this->eventParticipantId,
            'queue_ticket_id' => $this->queueTicketId,
            'next_service_post_id' => $this->nextServicePostId,
        ];
    }
}
