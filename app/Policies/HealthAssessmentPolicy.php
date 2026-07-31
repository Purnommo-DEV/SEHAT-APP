<?php

namespace App\Policies;

use App\Models\HealthAssessment;
use App\Models\User;

class HealthAssessmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('health.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('health.manage');
    }

    public function update(User $user, HealthAssessment $healthAssessment): bool
    {
        return $user->can('health.manage');
    }
}
