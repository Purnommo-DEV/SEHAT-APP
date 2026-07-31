<?php

namespace App\Events;

class ParticipantIneligible extends ParticipantWorkflowEvent
{
    public function broadcastAs(): string
    {
        return 'participant.ineligible';
    }
}
