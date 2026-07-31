<?php

namespace App\Services\Workflow\Behaviors;

use App\Data\BehaviorCompletionResult;
use App\Enums\ServicePostBehavior;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\ServicePost;
use App\Models\User;

interface ServicePostBehaviorStrategy
{
    public function behavior(): ServicePostBehavior;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function complete(
        Event $event,
        EventParticipant $eventParticipant,
        ServicePost $servicePost,
        array $attributes,
        User $actor,
    ): BehaviorCompletionResult;
}
