<?php

namespace App\Services\CheckIn;

use App\Models\Event;
use App\Models\QueueTicket;
use Illuminate\Database\Eloquent\Collection;

class RecentCheckInTicketService
{
    /**
     * @return Collection<int, QueueTicket>
     */
    public function forEvent(Event $event, int $limit = 20): Collection
    {
        return QueueTicket::query()
            ->select([
                'id',
                'event_id',
                'event_participant_id',
                'service_post_id',
                'queue_type',
                'number',
                'status',
                'created_at',
            ])
            ->where('event_id', $event->id)
            ->whereIn('id', QueueTicket::query()
                ->selectRaw('MIN(id)')
                ->where('event_id', $event->id)
                ->groupBy('event_participant_id'))
            ->with([
                'event:id',
                'event.settings:id,event_id,registration_number_format,registration_queue_prefix,registration_male_prefix,registration_female_prefix,registration_queue_digits',
                'eventParticipant:id,event_id,participant_id,registration_number,status,registration_order,completed_at',
                'eventParticipant.participant:id,name,phone,nik,gender',
                'eventParticipant.services:id,event_participant_id,service,status',
                'servicePost:id,name',
            ])
            ->latest('id')
            ->limit($limit)
            ->get();
    }
}
