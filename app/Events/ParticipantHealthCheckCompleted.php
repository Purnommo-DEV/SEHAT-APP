<?php

namespace App\Events;

class ParticipantHealthCheckCompleted extends ParticipantWorkflowEvent
{
    public function broadcastAs(): string
    {
        return 'participant.health-check-completed';
    }
}
