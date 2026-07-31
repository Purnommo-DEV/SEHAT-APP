<?php

namespace App\Events;

class ParticipantEligible extends ParticipantWorkflowEvent
{
    public function broadcastAs(): string
    {
        return 'participant.eligible';
    }
}
