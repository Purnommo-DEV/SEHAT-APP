<?php

namespace App\Events;

use App\Enums\AuditAction;
use App\Events\Concerns\QueuesBroadcasts;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServicePostUpdated implements ShouldBroadcast
{
    use Dispatchable, QueuesBroadcasts, SerializesModels;

    public function __construct(
        public readonly int $eventId,
        public readonly int $servicePostId,
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
        return 'service-post.updated';
    }

    /**
     * @return array{event_id: int, service_post_id: int, action: string}
     */
    public function broadcastWith(): array
    {
        return [
            'event_id' => $this->eventId,
            'service_post_id' => $this->servicePostId,
            'action' => $this->action->value,
        ];
    }
}
