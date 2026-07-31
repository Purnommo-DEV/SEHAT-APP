<?php

namespace App\Models;

use Database\Factories\HealthAssessmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $event_id
 * @property int $event_participant_id
 * @property int|null $service_post_id
 * @property string|null $blood_pressure
 * @property numeric-string|null $blood_sugar
 * @property numeric-string|null $cholesterol
 * @property numeric-string|null $uric_acid
 * @property string|null $notes
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class HealthAssessment extends Model
{
    /** @use HasFactory<HealthAssessmentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'event_participant_id',
        'service_post_id',
        'blood_pressure',
        'blood_sugar',
        'cholesterol',
        'uric_acid',
        'notes',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'blood_sugar' => 'decimal:2',
            'cholesterol' => 'decimal:2',
            'uric_acid' => 'decimal:2',
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<ServicePost, $this>
     */
    public function servicePost(): BelongsTo
    {
        return $this->belongsTo(ServicePost::class);
    }
}
