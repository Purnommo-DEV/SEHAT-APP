<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('events.manage');
    }

    public function view(User $user, Event $event): bool
    {
        return $user->can('events.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('events.manage');
    }

    public function update(User $user, Event $event): bool
    {
        return $user->can('events.manage');
    }

    public function updateDonationCapacity(User $user, Event $event): bool
    {
        return $user->can('event.update_donation_capacity');
    }

    public function resetQueue(User $user, Event $event): bool
    {
        return $user->can('event.reset_queue');
    }

    public function activate(User $user, Event $event): bool
    {
        return $user->can('events.manage');
    }

    public function complete(User $user, Event $event): bool
    {
        return $user->can('events.manage');
    }

    public function cancel(User $user, Event $event): bool
    {
        return $user->can('events.manage');
    }

    public function delete(User $user, Event $event): bool
    {
        return $user->can('events.manage');
    }
}
