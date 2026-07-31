<?php

namespace App\Events;

class ParticipantMovedToHealthCheck extends ParticipantWorkflowEvent
{
    public function broadcastAs(): string
    {
        return 'participant.moved-to-health-check';
    }
}
