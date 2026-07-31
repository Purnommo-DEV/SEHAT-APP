<?php

namespace App\Services\Monitor;

use App\Enums\ParticipantServiceType;
use App\Enums\QueueTicketStatus;
use App\Enums\ServicePostBehavior;
use App\Models\Event;
use App\Models\QueueTicket;
use App\Models\ServicePost;
use Illuminate\Support\Collection;

class MonitorService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $event = Event::query()
            ->active()
            ->with(['settings', 'servicePosts' => fn ($query) => $query
                ->where('is_active', true)
                ->whereIn('behavior', [
                    ServicePostBehavior::ScreeningForm->value,
                    ServicePostBehavior::DonationForm->value,
                    ServicePostBehavior::HealthForm->value,
                ])])
            ->first();

        if ($event === null) {
            return ['event' => null, 'queues' => []];
        }

        $tickets = QueueTicket::query()
            ->where('event_id', $event->id)
            ->whereIn('status', [
                QueueTicketStatus::Waiting->value,
                QueueTicketStatus::Calling->value,
                QueueTicketStatus::Serving->value,
            ])
            ->where(function ($query): void {
                $query
                    ->where(function ($donor): void {
                        $donor
                            ->whereHas('servicePost', fn ($post) => $post->whereIn('behavior', [
                                ServicePostBehavior::ScreeningForm->value,
                                ServicePostBehavior::DonationForm->value,
                            ]))
                            ->whereHas('eventParticipant.services', fn ($service) => $service
                                ->where('service', ParticipantServiceType::Donor->value));
                    })
                    ->orWhere(function ($health): void {
                        $health
                            ->whereHas('servicePost', fn ($post) => $post
                                ->where('behavior', ServicePostBehavior::HealthForm->value))
                            ->whereHas('eventParticipant.services', fn ($service) => $service
                                ->where('service', ParticipantServiceType::HealthCheck->value));
                    });
            })
            ->with(['eventParticipant.participant', 'servicePost'])
            ->orderBy('service_post_id')
            ->orderByRaw("case status when 'calling' then 0 when 'waiting' then 1 else 2 end")
            ->orderBy('number')
            ->get()
            ->each(fn (QueueTicket $ticket): QueueTicket => $ticket->setRelation('event', $event))
            ->groupBy('service_post_id');

        return [
            'event' => [
                'id' => $event->id,
                'code' => $event->code,
                'name' => $event->name,
                'location' => $event->location,
            ],
            'queues' => $event->servicePosts
                ->map(fn (ServicePost $post): array => $this->queueSnapshot($post, $tickets->get($post->id, collect())))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, QueueTicket>  $tickets
     * @return array<string, mixed>
     */
    private function queueSnapshot(ServicePost $post, Collection $tickets): array
    {
        $current = $tickets
            ->whereIn('status', [
                QueueTicketStatus::Calling,
                QueueTicketStatus::Serving,
            ])
            ->sortByDesc('called_at')
            ->first();

        return [
            'id' => $post->id,
            'label' => $post->name,
            'sequence' => $post->sequence,
            'behavior' => $post->behavior->value,
            'behavior_label' => $post->behavior->label(),
            'service_name' => $this->serviceName($post),
            'current' => $current instanceof QueueTicket ? [
                'id' => $current->id,
                'number' => $current->formattedNumber(),
                'participant_name' => $current->eventParticipant->participant->name,
                'participant_gender' => $current->eventParticipant->participant->gender->value,
                'participant_gender_label' => $current->eventParticipant->participant->gender->label(),
                'service_name' => $this->serviceName($post),
                'status' => $current->status->value,
                'status_label' => $current->status->label(),
                'instruction' => $current->status === QueueTicketStatus::Calling
                    ? "Silakan menuju {$post->name}"
                    : "Sedang dilayani di {$post->name}",
            ] : null,
            'waiting' => $tickets
                ->where('status', QueueTicketStatus::Waiting)
                ->take(5)
                ->map(fn (QueueTicket $ticket): array => [
                    'id' => $ticket->id,
                    'number' => $ticket->formattedNumber(),
                    'participant_name' => $ticket->eventParticipant->participant->name,
                    'participant_gender' => $ticket->eventParticipant->participant->gender->value,
                    'participant_gender_label' => $ticket->eventParticipant->participant->gender->label(),
                    'service_name' => $this->serviceName($post),
                    'status_label' => $ticket->status->label(),
                ])
                ->values()
                ->all(),
            'waiting_count' => $tickets
                ->where('status', QueueTicketStatus::Waiting)
                ->count(),
        ];
    }

    private function serviceName(ServicePost $post): string
    {
        return match ($post->behavior) {
            ServicePostBehavior::ScreeningForm,
            ServicePostBehavior::DonationForm => ParticipantServiceType::Donor->label(),
            ServicePostBehavior::HealthForm => 'Pemeriksaan Kesehatan',
            default => 'Pelayanan',
        };
    }
}
