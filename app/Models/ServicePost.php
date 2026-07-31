<?php

namespace App\Models;

use App\Enums\ServicePostBehavior;
use App\Enums\ServicePostType;
use Database\Factories\ServicePostFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $event_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property ServicePostType $type
 * @property ServicePostBehavior $behavior
 * @property string|null $queue_prefix
 * @property int $queue_number_digits
 * @property int $sequence
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ServicePost extends Model
{
    /** @use HasFactory<ServicePostFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'description',
        'type',
        'behavior',
        'queue_prefix',
        'queue_number_digits',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ServicePostType::class,
            'behavior' => ServicePostBehavior::class,
            'is_active' => 'boolean',
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
     * @return MorphMany<AuditLog, $this>
     */
    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'subject');
    }

    /**
     * @return HasMany<EventParticipant, $this>
     */
    public function currentParticipants(): HasMany
    {
        return $this->hasMany(EventParticipant::class, 'current_service_post_id');
    }

    /**
     * @return HasMany<QueueTicket, $this>
     */
    public function queueTickets(): HasMany
    {
        return $this->hasMany(QueueTicket::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function operators(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * @return HasMany<ServicePostSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(ServicePostSubmission::class);
    }
}
