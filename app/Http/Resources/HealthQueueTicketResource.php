<?php

namespace App\Http\Resources;

use App\Models\QueueTicket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin QueueTicket
 */
class HealthQueueTicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $assessment = $this->eventParticipant->healthAssessment;

        return [
            'id' => $this->id,
            'formatted_number' => $this->formattedNumber(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'participant' => [
                'id' => $this->eventParticipant->participant->id,
                'name' => $this->eventParticipant->participant->name,
                'gender' => $this->eventParticipant->participant->gender->value,
                'gender_label' => $this->eventParticipant->participant->gender->label(),
            ],
            'called_at' => $this->called_at?->toIso8601String(),
            'served_at' => $this->served_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'called_by' => $this->calledBy?->name,
            'has_assessment' => $assessment !== null,
            'urls' => [
                'call' => route('events.health.call', [$this->event_id, $this->resource]),
                'start' => route('events.health.start', [$this->event_id, $this->resource]),
                'skip' => route('events.health.skip', [$this->event_id, $this->resource]),
                'assessment' => route('events.health.assessments.edit', [$this->event_id, $this->resource]),
            ],
        ];
    }
}
