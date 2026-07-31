<?php

namespace App\Services\Workflow\Behaviors;

use App\Data\BehaviorCompletionResult;
use App\Enums\ServicePostBehavior;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\ServicePost;
use App\Models\ServicePostSubmission;
use App\Models\User;

class ConfirmationOnlyBehavior implements ServicePostBehaviorStrategy
{
    public function behavior(): ServicePostBehavior
    {
        return ServicePostBehavior::ConfirmationOnly;
    }

    public function complete(
        Event $event,
        EventParticipant $eventParticipant,
        ServicePost $servicePost,
        array $attributes,
        User $actor,
    ): BehaviorCompletionResult {
        $submission = ServicePostSubmission::query()->create([
            'event_id' => $event->id,
            'event_participant_id' => $eventParticipant->id,
            'service_post_id' => $servicePost->id,
            'payload' => null,
            'completed_by' => $actor->id,
            'completed_at' => now(),
        ]);

        return new BehaviorCompletionResult($submission);
    }
}
