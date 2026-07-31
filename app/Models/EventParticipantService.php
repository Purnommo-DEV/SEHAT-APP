<?php

namespace App\Models;

use App\Enums\ParticipantServiceStatus;
use App\Enums\ParticipantServiceType;
use Database\Factories\EventParticipantServiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A selected service is the source of truth for a participant's operational
 * path. It deliberately keeps donor and health-check state independent so a
 * participant may select both services without creating an invalid queue.
 *
 * @property int $id
 * @property int $event_id
 * @property int $event_participant_id
 * @property ParticipantServiceType $service
 * @property ParticipantServiceStatus $status
 * @property Carbon $selected_at
 * @property Carbon|null $eligibility_started_at
 * @property Carbon|null $eligibility_completed_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Event $event
 * @property-read EventParticipant $eventParticipant
 */
class EventParticipantService extends Model
{
    /** @use HasFactory<EventParticipantServiceFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'event_participant_id',
        'service',
        'status',
        'selected_at',
        'eligibility_started_at',
        'eligibility_completed_at',
        'started_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'service' => ParticipantServiceType::class,
            'status' => ParticipantServiceStatus::class,
            'selected_at' => 'datetime',
            'eligibility_started_at' => 'datetime',
            'eligibility_completed_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return BelongsTo<EventParticipant, $this>
     */
    public function eventParticipant(): BelongsTo
    {
        return $this->belongsTo(EventParticipant::class);
    }
}
