<?php

namespace App\Http\Resources;

use App\Models\EventParticipantService;
use App\Models\QueueTicket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin QueueTicket
 */
class ServiceQueueTicketResource extends JsonResource
{
    /**
     * @return array{
     *     id: int,
     *     number: string,
     *     status: string,
     *     status_label: string,
     *     participant: array{id: int, name: string, phone: string|null, gender: string, gender_value: string, services: list<string>},
     *     called_at: string|null,
     *     served_at: string|null,
     *     finished_at: string|null,
     *     called_by: string|null,
     *     urls: array{call: string, start: string, skip: string, cancel: string, complete: string}
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->formattedNumber(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'participant' => [
                'id' => $this->eventParticipant->participant->id,
                'name' => $this->eventParticipant->participant->name,
                'phone' => $this->eventParticipant->participant->phone,
                'gender' => $this->eventParticipant->participant->gender->label(),
                'gender_value' => $this->eventParticipant->participant->gender->value,
                'services' => array_values(
                    $this->eventParticipant->services
                        ->map(fn (EventParticipantService $service): string => $service->service->label())
                        ->all(),
                ),
            ],
            'called_at' => $this->called_at?->toIso8601String(),
            'served_at' => $this->served_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'called_by' => $this->calledBy?->name,
            'urls' => [
                'call' => route('events.service-queues.call', [$this->event_id, $this->service_post_id, $this->resource]),
                'start' => route('events.service-queues.start', [$this->event_id, $this->service_post_id, $this->resource]),
                'skip' => route('events.service-queues.skip', [$this->event_id, $this->service_post_id, $this->resource]),
                'cancel' => route('events.service-queues.cancel', [$this->event_id, $this->service_post_id, $this->resource]),
                'complete' => route('events.service-queues.complete', [$this->event_id, $this->service_post_id, $this->resource]),
            ],
        ];
    }
}
