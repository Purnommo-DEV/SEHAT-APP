<?php

namespace App\Policies;

use App\Models\DonorScreening;
use App\Models\User;

class DonorScreeningPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('screening.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('screening.manage');
    }

    public function view(User $user, DonorScreening $donorScreening): bool
    {
        return $user->can('screening.manage');
    }
}
