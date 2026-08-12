<?php

namespace App\Events;

use App\Enums\AuditAction;
use App\Events\Concerns\QueuesBroadcasts;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServiceQueueUpdated implements ShouldBroadcast
{
    use Dispatchable, QueuesBroadcasts, SerializesModels;

    public function __construct(
        public readonly int $eventId,
        public readonly int $servicePostId,
        public readonly int $queueTicketId,
        public readonly AuditAction $action,
        public readonly ?int $nextServicePostId = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("events.{$this->eventId}")];
    }

    public function broadcastAs(): string
    {
        return 'service.queue.updated';
    }

    /**
     * @return array{
     *     event_id: int,
     *     service_post_id: int,
     *     queue_ticket_id: int,
     *     action: string,
     *     next_service_post_id: int|null
     * }
     */
    public function broadcastWith(): array
    {
        return [
            'event_id' => $this->eventId,
            'service_post_id' => $this->servicePostId,
            'queue_ticket_id' => $this->queueTicketId,
            'action' => $this->action->value,
            'next_service_post_id' => $this->nextServicePostId,
        ];
    }
}
