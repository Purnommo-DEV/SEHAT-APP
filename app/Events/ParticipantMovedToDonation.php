<?php

namespace App\Events;

class ParticipantMovedToDonation extends ParticipantWorkflowEvent
{
    public function broadcastAs(): string
    {
        return 'participant.moved-to-donation';
    }
}
