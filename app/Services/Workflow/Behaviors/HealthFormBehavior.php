<?php

namespace App\Services\Workflow\Behaviors;

use App\Data\BehaviorCompletionResult;
use App\Enums\ServicePostBehavior;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\HealthAssessment;
use App\Models\ServicePost;
use App\Models\User;

class HealthFormBehavior implements ServicePostBehaviorStrategy
{
    public function behavior(): ServicePostBehavior
    {
        return ServicePostBehavior::HealthForm;
    }

    public function complete(
        Event $event,
        EventParticipant $eventParticipant,
        ServicePost $servicePost,
        array $attributes,
        User $actor,
    ): BehaviorCompletionResult {
        $assessment = HealthAssessment::query()->create([
            'event_id' => $event->id,
            'event_participant_id' => $eventParticipant->id,
            'service_post_id' => $servicePost->id,
            'blood_pressure' => $attributes['blood_pressure'] ?? null,
            'blood_sugar' => $attributes['blood_sugar'] ?? null,
            'cholesterol' => $attributes['cholesterol'] ?? null,
            'uric_acid' => $attributes['uric_acid'] ?? null,
            'notes' => $attributes['notes'] ?? null,
            'created_by' => $actor->id,
        ]);

        return new BehaviorCompletionResult($assessment);
    }
}
