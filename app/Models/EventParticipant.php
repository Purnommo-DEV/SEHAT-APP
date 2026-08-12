<?php

namespace App\Models;

use App\Enums\ParticipantGender;
use App\Enums\ParticipantStatus;
use Database\Factories\EventParticipantFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $event_id
 * @property int $participant_id
 * @property int|null $registration_number
 * @property string|null $registration_number_scope
 * @property int|null $active_registration_number
 * @property int|null $current_service_post_id
 * @property ParticipantStatus $status
 * @property Carbon|null $checked_in_at
 * @property int|null $checked_in_by
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Participant $participant
 * @property-read ServicePost|null $currentServicePost
 * @property-read User|null $checkedInBy
 * @property-read HealthAssessment|null $healthAssessment
 * @property-read DonorScreening|null $donorScreening
 * @property-read DonorScreening|null $latestDonorScreening
 * @property-read Collection<int, EventParticipantService> $services
 * @property-read Collection<int, EventParticipantStatusHistory> $statusHistories
 */
class EventParticipant extends Model
{
    /** @use HasFactory<EventParticipantFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'participant_id',
        'registration_number',
        'registration_number_scope',
        'active_registration_number',
        'current_service_post_id',
        'status',
        'checked_in_at',
        'checked_in_by',
        'completed_at',
        'cancelled_at',
        'cancelled_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ParticipantStatus::class,
            'checked_in_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
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
     * @return BelongsTo<Participant, $this>
     */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    /**
     * @return BelongsTo<ServicePost, $this>
     */
    public function currentServicePost(): BelongsTo
    {
        return $this->belongsTo(ServicePost::class, 'current_service_post_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * @return HasMany<QueueTicket, $this>
     */
    public function queueTickets(): HasMany
    {
        return $this->hasMany(QueueTicket::class);
    }

    /**
     * @return HasOne<HealthAssessment, $this>
     */
    public function healthAssessment(): HasOne
    {
        return $this->hasOne(HealthAssessment::class);
    }

    /**
     * @return HasMany<HealthAssessment, $this>
     */
    public function healthAssessments(): HasMany
    {
        return $this->hasMany(HealthAssessment::class);
    }

    /**
     * @return HasOne<DonorScreening, $this>
     */
    public function donorScreening(): HasOne
    {
        return $this->hasOne(DonorScreening::class);
    }

    /**
     * @return HasOne<DonorScreening, $this>
     */
    public function latestDonorScreening(): HasOne
    {
        return $this->hasOne(DonorScreening::class)->latestOfMany();
    }

    /**
     * @return HasMany<DonorScreening, $this>
     */
    public function donorScreenings(): HasMany
    {
        return $this->hasMany(DonorScreening::class);
    }

    /**
     * @return HasMany<ServicePostSubmission, $this>
     */
    public function servicePostSubmissions(): HasMany
    {
        return $this->hasMany(ServicePostSubmission::class);
    }

    /**
     * @return HasMany<EventParticipantService, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(EventParticipantService::class);
    }

    /**
     * @return HasMany<EventParticipantStatusHistory, $this>
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(EventParticipantStatusHistory::class);
    }

    public function formattedRegistrationNumber(EventSetting $settings): ?string
    {
        if ($this->registration_number === null) {
            return null;
        }

        $participant = $this->relationLoaded('participant')
            ? $this->getRelation('participant')
            : $this->participant()->first(['id', 'gender']);
        $gender = $participant instanceof Participant
            ? $participant->gender
            : ParticipantGender::Male;

        return $settings->registrationPrefix($gender)
            .str_pad((string) $this->registration_number, $settings->registration_queue_digits, '0', STR_PAD_LEFT);
    }

    public static function registrationNumberScopeFor(ParticipantGender $gender): string
    {
        return "gender:{$gender->value}";
    }
}
