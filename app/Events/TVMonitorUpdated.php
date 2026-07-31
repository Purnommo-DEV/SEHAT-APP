<?php

namespace App\Events;

class TVMonitorUpdated extends OperationalSnapshotEvent
{
    public function broadcastAs(): string
    {
        return 'tv-monitor.updated';
    }
}
