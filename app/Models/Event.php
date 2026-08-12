<?php

namespace App\Models;

use App\Enums\EventStatus;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string|null $location
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property EventStatus $status
 * @property string|null $active_marker
 * @property Carbon|null $ended_at
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read EventSetting|null $settings
 * @property-read Collection<int, EventDonationCapacityLane> $donationCapacityLanes
 */
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'description',
        'location',
        'starts_at',
        'ends_at',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'ended_at' => 'datetime',
            'status' => EventStatus::class,
        ];
    }

    /**
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active_marker', 'active');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasOne<EventSetting, $this>
     */
    public function settings(): HasOne
    {
        return $this->hasOne(EventSetting::class);
    }

    /**
     * @return HasMany<EventDonationCapacityLane, $this>
     */
    public function donationCapacityLanes(): HasMany
    {
        return $this->hasMany(EventDonationCapacityLane::class);
    }

    /**
     * @return HasMany<AuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * @return HasMany<ServicePost, $this>
     */
    public function servicePosts(): HasMany
    {
        return $this->hasMany(ServicePost::class)->orderBy('sequence');
    }

    /**
     * @return HasMany<EventParticipant, $this>
     */
    public function eventParticipants(): HasMany
    {
        return $this->hasMany(EventParticipant::class);
    }

    /**
     * @return HasMany<EventParticipantService, $this>
     */
    public function participantServices(): HasMany
    {
        return $this->hasMany(EventParticipantService::class);
    }

    /**
     * @return HasMany<QueueTicket, $this>
     */
    public function queueTickets(): HasMany
    {
        return $this->hasMany(QueueTicket::class);
    }

    /**
     * @return HasMany<HealthAssessment, $this>
     */
    public function healthAssessments(): HasMany
    {
        return $this->hasMany(HealthAssessment::class);
    }

    /**
     * @return HasMany<DonorScreening, $this>
     */
    public function donorScreenings(): HasMany
    {
        return $this->hasMany(DonorScreening::class);
    }
}
