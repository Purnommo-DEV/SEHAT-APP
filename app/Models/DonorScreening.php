<?php

namespace App\Models;

use App\Enums\ScreeningResult;
use Database\Factories\DonorScreeningFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $event_id
 * @property int $event_participant_id
 * @property int|null $service_post_id
 * @property ScreeningResult $result
 * @property string|null $reason
 * @property int $screened_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $screenedBy
 */
class DonorScreening extends Model
{
    /** @use HasFactory<DonorScreeningFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'event_participant_id',
        'service_post_id',
        'result',
        'reason',
        'screened_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'result' => ScreeningResult::class,
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
    public function screenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'screened_by');
    }

    /**
     * @return BelongsTo<ServicePost, $this>
     */
    public function servicePost(): BelongsTo
    {
        return $this->belongsTo(ServicePost::class);
    }
}
