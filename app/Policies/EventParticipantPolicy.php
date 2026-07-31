<?php

namespace App\Policies;

use App\Enums\ParticipantStatus;
use App\Enums\QueueTicketStatus;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\User;

class EventParticipantPolicy
{
    public function viewAny(User $user, Event $event): bool
    {
        return $user->can('check-in.manage');
    }

    public function view(User $user, EventParticipant $eventParticipant): bool
    {
        return $user->can('check-in.manage');
    }

    public function create(User $user, Event $event): bool
    {
        return $user->can('check-in.manage');
    }

    public function cancel(User $user, EventParticipant $eventParticipant): bool
    {
        if (! $user->can('check-in.manage')
            || ! in_array($eventParticipant->status, [
                ParticipantStatus::Registered,
                ParticipantStatus::WaitingService,
                ParticipantStatus::WaitingHealth,
                ParticipantStatus::WaitingScreening,
            ], true)
        ) {
            return false;
        }

        return ! $eventParticipant->queueTickets()
            ->whereIn('status', [
                QueueTicketStatus::Serving->value,
                QueueTicketStatus::Finished->value,
            ])
            ->exists();
    }
}
