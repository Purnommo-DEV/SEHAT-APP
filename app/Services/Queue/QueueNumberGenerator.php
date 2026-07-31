<?php

namespace App\Services\Queue;

use App\Data\DonorQueueNumber;
use App\Enums\ParticipantGender;
use App\Enums\QueueType;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\QueueTicket;
use App\Models\ServicePost;
use Illuminate\Database\Eloquent\Builder;

class QueueNumberGenerator
{
    /**
     * The caller must hold a lock on the event row inside the current transaction.
     */
    public function next(Event $event, ServicePost|QueueType $queue): int
    {
        $query = QueueTicket::query()
            ->where('event_id', $event->id)
            ->where('number_scope', QueueTicket::numberScopeFor(
                $queue instanceof ServicePost ? QueueType::General : $queue,
                $queue instanceof ServicePost ? $queue->id : null,
            ));

        return $this->smallestAvailable($query, 'active_number');
    }

    /**
     * Donor queue mode and lane are always derived from the locked Event
     * settings. The caller must hold a lock on the event row inside the
     * current transaction.
     */
    public function nextDonor(Event $event, ParticipantGender $gender): DonorQueueNumber
    {
        $settings = $event->settings()
            ->lockForUpdate()
            ->firstOrFail();
        $queueType = $settings->donorQueueType($gender);

        return new DonorQueueNumber(
            queueType: $queueType,
            number: $this->next($event, $queueType),
        );
    }

    /**
     * Registration numbers share one numeric sequence across all genders.
     *
     * The caller must hold a lock on the event row inside the current
     * transaction before requesting the next number.
     */
    public function nextRegistration(Event $event): int
    {
        return $this->smallestAvailable(
            EventParticipant::query()
                ->where('event_id', $event->id)
                ->whereNotNull('active_registration_number'),
            'active_registration_number',
        );
    }

    /**
     * @param  Builder<QueueTicket>|Builder<EventParticipant>  $query
     */
    private function smallestAvailable(Builder $query, string $column = 'number'): int
    {
        $candidate = 1;
        $numbers = $query
            ->whereNotNull($column)
            ->lockForUpdate()
            ->orderBy($column)
            ->pluck($column);

        foreach ($numbers as $number) {
            $value = (int) $number;

            if ($value < $candidate) {
                continue;
            }

            if ($value > $candidate) {
                break;
            }

            $candidate++;
        }

        return $candidate;
    }
}
