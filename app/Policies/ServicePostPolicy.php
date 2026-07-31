<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\ServicePost;
use App\Models\User;

class ServicePostPolicy
{
    public function viewAny(User $user, Event $event): bool
    {
        return $user->can('service-posts.manage');
    }

    public function view(User $user, ServicePost $servicePost): bool
    {
        return $user->can('service-posts.manage');
    }

    public function create(User $user, Event $event): bool
    {
        return $user->can('service-posts.manage');
    }

    public function update(User $user, ServicePost $servicePost): bool
    {
        return $user->can('service-posts.manage');
    }

    public function delete(User $user, ServicePost $servicePost): bool
    {
        return $user->can('service-posts.manage');
    }
}
