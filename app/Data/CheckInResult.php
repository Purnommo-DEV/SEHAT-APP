<?php

namespace App\Data;

use App\Models\EventParticipant;
use App\Models\QueueTicket;

final readonly class CheckInResult
{
    public function __construct(
        public EventParticipant $eventParticipant,
        public QueueTicket $queueTicket,
        public string $registrationNumber,
        public bool $alreadyCheckedIn,
        public bool $recoveredMissingTicket = false,
    ) {}
}
