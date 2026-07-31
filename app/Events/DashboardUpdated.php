<?php

namespace App\Events;

class DashboardUpdated extends OperationalSnapshotEvent
{
    public function broadcastAs(): string
    {
        return 'dashboard.updated';
    }
}
