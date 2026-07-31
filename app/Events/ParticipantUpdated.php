<?php

namespace App\Events;

use App\Enums\AuditAction;
use App\Events\Concerns\QueuesBroadcasts;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ParticipantUpdated implements ShouldBroadcast
{
    use Dispatchable, QueuesBroadcasts, SerializesModels;

    public function __construct(
        public readonly int $participantId,
        public readonly AuditAction $action,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('participants')];
    }

    public function broadcastAs(): string
    {
        return 'participant.updated';
    }

    /**
     * @return array{participant_id: int, action: string}
     */
    public function broadcastWith(): array
    {
        return [
            'participant_id' => $this->participantId,
            'action' => $this->action->value,
        ];
    }
}
