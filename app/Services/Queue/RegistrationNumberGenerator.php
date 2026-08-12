<?php

namespace App\Services\Queue;

use App\Enums\ParticipantGender;
use App\Models\Event;

class RegistrationNumberGenerator
{
    public function __construct(private readonly QueueNumberGenerator $queueNumberGenerator) {}

    /**
     * The caller must hold a lock on the event row inside the current transaction.
     */
    public function next(Event $event, ParticipantGender $gender): int
    {
        return $this->queueNumberGenerator->nextRegistration($event, $gender);
    }
}
