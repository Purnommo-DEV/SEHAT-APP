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
     * The caller must hold the lock appropriate to the selected queue lane.
     * Operational donor transitions use a gender capacity lane, not an event
     * mutex; this query locks only existing numbers in its own number scope.
     */
    public function next(Event $event, ServicePost|QueueType $queue, bool $lockExistingNumbers = true): int
    {
        $query = QueueTicket::query()
            ->where('event_id', $event->id)
            ->where('number_scope', QueueTicket::numberScopeFor(
                $queue instanceof ServicePost ? QueueType::General : $queue,
                $queue instanceof ServicePost ? $queue->id : null,
            ));

        return $this->smallestAvailable($query, 'active_number', $lockExistingNumbers);
    }

    /**
     * Donor queue mode and lane are derived from event settings. Operational
     * reservations hold a gender-specific capacity lock, not a global event
     * lock, so reading configuration here must remain non-blocking.
     */
    public function nextDonor(Event $event, ParticipantGender $gender): DonorQueueNumber
    {
        $settings = $event->settings()->firstOrFail();
        $queueType = $settings->donorQueueType($gender);

        return new DonorQueueNumber(
            queueType: $queueType,
            // This lock is scoped to the chosen number lane, never to the
            // event or capacity settings. In global-number mode it protects
            // that one inherently shared number sequence only.
            number: $this->next($event, $queueType),
        );
    }

    /**
     * Registration numbers advance independently for each gender in an
     * event. The event row lock held by the caller serializes concurrent
     * registrations, while the scoped unique index is the database backstop.
     */
    public function nextRegistration(Event $event, ParticipantGender $gender): int
    {
        $lastNumber = EventParticipant::query()
            ->where('event_id', $event->id)
            ->where('registration_number_scope', EventParticipant::registrationNumberScopeFor($gender))
            ->whereNotNull('active_registration_number')
            ->orderByDesc('active_registration_number')
            ->lockForUpdate()
            ->value('active_registration_number');

        return ((int) $lastNumber) + 1;
    }

    /**
     * @param  Builder<QueueTicket>|Builder<EventParticipant>  $query
     */
    private function smallestAvailable(Builder $query, string $column = 'number', bool $lockExistingNumbers = true): int
    {
        $candidate = 1;
        $numbersQuery = $query
            ->whereNotNull($column)
            ->orderBy($column);

        if ($lockExistingNumbers) {
            $numbersQuery->lockForUpdate();
        }

        $numbers = $numbersQuery->pluck($column);

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
