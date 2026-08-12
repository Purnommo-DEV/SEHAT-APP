<?php

namespace App\Http\Resources;

use App\Enums\ParticipantServiceType;
use App\Enums\ParticipantStatus;
use App\Enums\QueueType;
use App\Models\EventParticipant;
use App\Models\EventParticipantService;
use App\Models\QueueTicket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EventParticipant
 */
class OperationalParticipantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $donorTicket = $this->donorTicket();
        $hasDonorService = $this->services
            ->contains(fn (EventParticipantService $service): bool => $service->service === ParticipantServiceType::Donor);

        return [
            'id' => $this->id,
            'number' => $this->formattedDonorNumber($donorTicket)
                ?? $this->formattedRegistrationNumber($this->event->settings)
                ?? '-',
            'registration_number' => $this->formattedRegistrationNumber($this->event->settings),
            'sort_number' => $this->registration_number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'position' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'completed_at' => $this->completed_at?->toIso8601String(),
            'participant' => [
                'id' => $this->participant->id,
                'name' => $this->participant->name,
                'gender' => $this->participant->gender->value,
                'gender_label' => $this->participant->gender->label(),
            ],
            'services' => $this->services
                ->map(fn (EventParticipantService $service): array => [
                    'value' => $service->service->value,
                    'label' => $service->service->label(),
                ])
                ->values()
                ->all(),
            'can_donate' => $this->status === ParticipantStatus::HealthCheck && $hasDonorService,
            'can_complete_before_donation' => $this->status === ParticipantStatus::HealthCheck,
            'can_complete_health_only' => $this->status === ParticipantStatus::HealthCheck && ! $hasDonorService,
            'urls' => [
                'health_check' => route('events.operations.health-check.start', [$this->event_id, $this->resource]),
                'donate' => route('events.operations.donating.start', [$this->event_id, $this->resource]),
                'complete' => route('events.operations.completed.store', [$this->event_id, $this->resource]),
                'complete_before_donation' => route('events.operations.health-check.complete', [$this->event_id, $this->resource]),
                'complete_health_only' => route('events.operations.health-check.complete', [$this->event_id, $this->resource]),
            ],
        ];
    }

    private function donorTicket(): ?QueueTicket
    {
        $ticket = $this->queueTickets
            ->first(fn (QueueTicket $queueTicket): bool => in_array($queueTicket->queue_type, [
                QueueType::DonorGlobal,
                QueueType::MaleDonor,
                QueueType::FemaleDonor,
            ], true));

        return $ticket instanceof QueueTicket ? $ticket : null;
    }

    private function formattedDonorNumber(?QueueTicket $ticket): ?string
    {
        if (! $ticket instanceof QueueTicket) {
            return null;
        }

        $settings = $this->event->settings;

        return $settings->donorPrefix($ticket->queue_type)
            .str_pad((string) $ticket->number, $settings->donor_queue_digits, '0', STR_PAD_LEFT);
    }
}
