<?php

namespace App\Events;

class QueueUpdated extends OperationalSnapshotEvent
{
    public function broadcastAs(): string
    {
        return 'queue.updated';
    }
}
