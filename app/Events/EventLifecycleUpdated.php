<?php

namespace App\Events;

use App\Enums\AuditAction;
use App\Events\Concerns\QueuesBroadcasts;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EventLifecycleUpdated implements ShouldBroadcast
{
    use Dispatchable, QueuesBroadcasts, SerializesModels;

    public function __construct(
        public readonly int $eventId,
        public readonly AuditAction $action,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('events'),
            new PrivateChannel("events.{$this->eventId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'event.lifecycle.updated';
    }

    /**
     * @return array{event_id: int, action: string}
     */
    public function broadcastWith(): array
    {
        return [
            'event_id' => $this->eventId,
            'action' => $this->action->value,
        ];
    }
}
