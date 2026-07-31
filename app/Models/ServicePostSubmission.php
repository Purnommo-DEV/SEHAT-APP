<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $event_id
 * @property int $event_participant_id
 * @property int $service_post_id
 * @property array<string, mixed>|null $payload
 * @property int $completed_by
 * @property Carbon $completed_at
 */
class ServicePostSubmission extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'event_participant_id',
        'service_post_id',
        'payload',
        'completed_by',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<EventParticipant, $this>
     */
    public function eventParticipant(): BelongsTo
    {
        return $this->belongsTo(EventParticipant::class);
    }

    /**
     * @return BelongsTo<ServicePost, $this>
     */
    public function servicePost(): BelongsTo
    {
        return $this->belongsTo(ServicePost::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
