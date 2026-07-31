<?php

namespace App\Services\Workflow\Behaviors;

use App\Data\BehaviorCompletionResult;
use App\Enums\ScreeningResult;
use App\Enums\ServicePostBehavior;
use App\Models\DonorScreening;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\ServicePost;
use App\Models\User;

class ScreeningFormBehavior implements ServicePostBehaviorStrategy
{
    public function behavior(): ServicePostBehavior
    {
        return ServicePostBehavior::ScreeningForm;
    }

    public function complete(
        Event $event,
        EventParticipant $eventParticipant,
        ServicePost $servicePost,
        array $attributes,
        User $actor,
    ): BehaviorCompletionResult {
        $result = ScreeningResult::from($attributes['result']);
        $screening = DonorScreening::query()->create([
            'event_id' => $event->id,
            'event_participant_id' => $eventParticipant->id,
            'service_post_id' => $servicePost->id,
            'result' => $result,
            'reason' => $result === ScreeningResult::NotEligible ? ($attributes['reason'] ?? null) : null,
            'screened_by' => $actor->id,
        ]);

        return new BehaviorCompletionResult(
            record: $screening,
            screeningResult: $result,
        );
    }
}
