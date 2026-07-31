<?php

namespace App\Events;

class ParticipantMovedToEligibility extends ParticipantWorkflowEvent
{
    public function broadcastAs(): string
    {
        return 'participant.moved-to-eligibility';
    }
}
