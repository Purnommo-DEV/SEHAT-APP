<?php

namespace App\Events;

use App\Enums\AuditAction;
use App\Events\Concerns\QueuesBroadcasts;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DonorQueueUpdated implements ShouldBroadcast
{
    use Dispatchable, QueuesBroadcasts, SerializesModels;

    public function __construct(
        public readonly int $eventId,
        public readonly int $queueTicketId,
        public readonly AuditAction $action,
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
        return 'donor.queue.updated';
    }

    /**
     * @return array<string, int|string>
     */
    public function broadcastWith(): array
    {
        return [
            'event_id' => $this->eventId,
            'queue_ticket_id' => $this->queueTicketId,
            'action' => $this->action->value,
        ];
    }
}
