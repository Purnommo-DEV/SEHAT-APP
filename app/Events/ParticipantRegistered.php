<?php

namespace App\Events;

class ParticipantRegistered extends ParticipantWorkflowEvent
{
    public function broadcastAs(): string
    {
        return 'participant.registered';
    }
}
