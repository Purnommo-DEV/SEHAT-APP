<?php

namespace App\Policies;

use App\Models\User;

class QueueTicketPolicy
{
    public function viewDonationQueue(User $user): bool
    {
        return $user->can('donation.manage');
    }

    public function manageDonation(User $user): bool
    {
        return $user->can('donation.manage');
    }
}
