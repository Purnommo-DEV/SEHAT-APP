<?php

namespace App\Http\Resources;

use App\Models\QueueTicket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin QueueTicket
 */
class DonorQueueTicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'formatted_number' => $this->formattedNumber(),
            'queue_type' => $this->queue_type->value,
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
            'urls' => [
                'call' => route('events.donation.call', [$this->event_id, $this->resource]),
                'start' => route('events.donation.start', [$this->event_id, $this->resource]),
                'skip' => route('events.donation.skip', [$this->event_id, $this->resource]),
                'cancel' => route('events.donation.cancel', [$this->event_id, $this->resource]),
                'complete' => route('events.donation.complete', [$this->event_id, $this->resource]),
            ],
        ];
    }
}
