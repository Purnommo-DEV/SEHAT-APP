<?php

namespace App\Policies;

use App\Models\User;

class MonitorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('monitor.view');
    }
}
