<?php

namespace App\Http\Controllers\Participant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Participant\ParticipantAutocompleteRequest;
use App\Http\Resources\ParticipantResource;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Participant;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ParticipantAutocompleteController extends Controller
{
    public function __invoke(
        ParticipantAutocompleteRequest $request,
        Event $event,
    ): AnonymousResourceCollection {
        $term = (string) $request->validated('q');
        $normalizedTerm = mb_strtolower($term);
        $participants = Participant::query()
            ->select(['id', 'nik', 'name', 'phone', 'gender', 'birth_date', 'address'])
            ->matching($term)
            ->with(['eventParticipants' => fn ($eventParticipants) => $eventParticipants
                ->where('event_id', $event->id)
                ->select([
                    'id',
                    'event_id',
                    'participant_id',
                    'registration_number',
                    'status',
                    'checked_in_at',
                    'registration_order',
                ])])
            ->orderByRaw(
                <<<'SQL'
                    CASE
                        WHEN LOWER(name) = ? THEN 0
                        WHEN LOWER(name) LIKE ? THEN 1
                        WHEN phone LIKE ? THEN 2
                        ELSE 3
                    END
                SQL,
                [$normalizedTerm, "{$normalizedTerm}%", "{$term}%"],
            )
            ->orderBy('name')
            ->limit(10)
            ->get();

        $event->loadMissing('settings');

        $participants->each(function (Participant $participant) use ($event): void {
            $registration = $participant->eventParticipants->first();
            if ($registration instanceof EventParticipant) {
                $registration->setRelation('participant', $participant);
            }

            $participant->setRelation('registrationForEvent', $registration);
            $participant->setRelation('registrationEventSettings', $event->settings);
        });

        return ParticipantResource::collection($participants);
    }
}
