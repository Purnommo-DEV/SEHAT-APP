<?php

namespace App\Services\Workflow;

use App\Enums\PermissionName;
use App\Enums\ServicePostBehavior;
use App\Models\Event;
use App\Models\ServicePost;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class ServicePostAccessService
{
    /**
     * @return Collection<int, ServicePost>
     */
    public function postsForUser(Event $event, User $user): Collection
    {
        $posts = ServicePost::query()
            ->where('event_id', $event->id)
            ->where('is_active', true)
            ->with('operators:id,name,email')
            ->orderBy('sequence')
            ->get();

        if ($user->can(PermissionName::ManageEvents->value)) {
            return $posts;
        }

        return $posts
            ->filter(fn (ServicePost $post): bool => $this->canManage($post, $user))
            ->values();
    }

    public function canManage(ServicePost $servicePost, User $user): bool
    {
        if ($user->can(PermissionName::ManageEvents->value)) {
            return true;
        }

        if ($servicePost->relationLoaded('operators')) {
            if ($servicePost->operators->contains('id', $user->id)) {
                return true;
            }
        } elseif ($servicePost->operators()->whereKey($user->id)->exists()) {
            return true;
        }

        return match ($servicePost->behavior) {
            ServicePostBehavior::HealthForm => $user->can(PermissionName::ManageHealth->value),
            ServicePostBehavior::ScreeningForm => $user->can(PermissionName::ManageScreening->value),
            ServicePostBehavior::DonationForm => $user->can(PermissionName::ManageDonation->value),
            ServicePostBehavior::ConfirmationOnly,
            ServicePostBehavior::CustomForm => false,
        };
    }
}
