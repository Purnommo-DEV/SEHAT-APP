<?php

namespace App\Events\Concerns;

trait QueuesBroadcasts
{
    public bool $afterCommit = true;

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }
}
