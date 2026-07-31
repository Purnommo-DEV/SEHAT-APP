<?php

use App\Enums\PermissionName;
use App\Models\Event;
use App\Models\ServicePost;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (User $user, int $id): bool {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('events', function (User $user): bool {
    return $user->canAny([
        PermissionName::ManageEvents->value,
        PermissionName::ViewDashboard->value,
        PermissionName::ViewMonitor->value,
    ]);
});

Broadcast::channel('participants', function (User $user): bool {
    return $user->can(PermissionName::ManageParticipants->value);
});

Broadcast::channel('events.{eventId}', function (User $user, int $eventId): bool {
    if (! Event::query()->whereKey($eventId)->exists()) {
        return false;
    }

    if ($user->canAny([
        PermissionName::ManageEvents->value,
        PermissionName::ViewDashboard->value,
        PermissionName::ManageServicePosts->value,
        PermissionName::ManageCheckIn->value,
        PermissionName::ManageHealth->value,
        PermissionName::ManageScreening->value,
        PermissionName::ManageDonation->value,
        PermissionName::ViewMonitor->value,
        PermissionName::ViewReports->value,
    ])) {
        return true;
    }

    return $user->can(PermissionName::ManageOwnQueue->value)
        && ServicePost::query()
            ->where('event_id', $eventId)
            ->whereHas('operators', fn ($query) => $query->whereKey($user->id))
            ->exists();
});
