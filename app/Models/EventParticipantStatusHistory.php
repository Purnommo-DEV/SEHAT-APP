<?php

namespace App\Models;

use App\Enums\ParticipantStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Immutable operational transition history for an event participant.
 *
 * @property int $id
 * @property int $event_id
 * @property int $event_participant_id
 * @property ParticipantStatus|null $from_status
 * @property ParticipantStatus $to_status
 * @property int|null $changed_by
 * @property Carbon $created_at
 * @property-read Event $event
 * @property-read EventParticipant $eventParticipant
 * @property-read User|null $changedBy
 */
class EventParticipantStatusHistory extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'event_participant_id',
        'from_status',
        'to_status',
        'changed_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => ParticipantStatus::class,
            'to_status' => ParticipantStatus::class,
            'created_at' => 'datetime',
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
