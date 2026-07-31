<?php

namespace App\Events;

class ParticipantDonationCompleted extends ParticipantWorkflowEvent
{
    public function broadcastAs(): string
    {
        return 'participant.donation-completed';
    }
}
