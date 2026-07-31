<?php

namespace App\Http\Resources;

use App\Models\EventParticipantService;
use App\Models\QueueTicket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin QueueTicket
 */
class QueueTicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'formatted_number' => $this->formattedNumber(),
            'registration_number' => $this->eventParticipant->formattedRegistrationNumber($this->event->settings),
            'queue_type' => $this->queue_type->value,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'participant' => [
                'id' => $this->eventParticipant->participant->id,
                'name' => $this->eventParticipant->participant->name,
                'phone' => $this->eventParticipant->participant->phone,
                'nik' => $this->eventParticipant->participant->nik,
                'gender' => $this->eventParticipant->participant->gender->value,
                'gender_label' => $this->eventParticipant->participant->gender->label(),
            ],
            'service_post' => [
                'id' => $this->servicePost->id,
                'name' => $this->servicePost->name,
            ],
            'services' => $this->eventParticipant->services
                ->map(fn (EventParticipantService $service): array => [
                    'service' => $service->service->value,
                    'label' => $service->service->label(),
                    'status' => $service->status->value,
                    'status_label' => $service->status->label(),
                ])
                ->values()
                ->all(),
            'created_at' => $this->created_at?->toIso8601String(),
            'urls' => [
                'show' => route('events.check-ins.show', [$this->event_id, $this->event_participant_id]),
            ],
        ];
    }
}
