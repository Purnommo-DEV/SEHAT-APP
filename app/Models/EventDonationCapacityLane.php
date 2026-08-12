<?php

namespace App\Models;

use App\Enums\ParticipantGender;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row-level transaction mutex for one event and participant gender.
 * Capacity values remain in EventSetting; this table deliberately holds no
 * duplicate configuration.
 *
 * @property int $id
 * @property int $event_id
 * @property ParticipantGender $gender
 */
class EventDonationCapacityLane extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['event_id', 'gender'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['gender' => ParticipantGender::class];
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
