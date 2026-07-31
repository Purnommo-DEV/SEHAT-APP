<?php

namespace App\Models;

use App\Enums\DonorNumberMode;
use App\Enums\QueueTicketStatus;
use App\Enums\QueueType;
use App\Enums\RegistrationNumberFormat;
use App\Enums\ServicePostBehavior;
use App\Enums\ServicePostType;
use Database\Factories\QueueTicketFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $event_id
 * @property int $event_participant_id
 * @property int $service_post_id
 * @property QueueType $queue_type
 * @property int $number
 * @property string $number_scope
 * @property int|null $active_number
 * @property QueueTicketStatus $status
 * @property Carbon|null $called_at
 * @property int|null $called_by
 * @property Carbon|null $served_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Event $event
 * @property-read EventParticipant $eventParticipant
 * @property-read ServicePost $servicePost
 * @property-read User|null $calledBy
 */
class QueueTicket extends Model
{
    /** @use HasFactory<QueueTicketFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'event_participant_id',
        'service_post_id',
        'queue_type',
        'number',
        'number_scope',
        'active_number',
        'status',
        'called_at',
        'called_by',
        'served_at',
        'finished_at',
        'cancelled_at',
        'cancelled_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'queue_type' => QueueType::class,
            'status' => QueueTicketStatus::class,
            'called_at' => 'datetime',
            'served_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (QueueTicket $ticket): void {
            $ticket->number_scope ??= self::numberScopeFor(
                $ticket->queue_type,
                $ticket->service_post_id,
            );
            $ticket->active_number = $ticket->status === QueueTicketStatus::Cancelled
                ? null
                : $ticket->number;
        });
    }

    public static function numberScopeFor(QueueType $queueType, ?int $servicePostId = null): string
    {
        if (in_array($queueType, [
            QueueType::DonorGlobal,
            QueueType::MaleDonor,
            QueueType::FemaleDonor,
        ], true)) {
            return "lane:{$queueType->value}";
        }

        return 'post:'.($servicePostId ?? 0);
    }

    public function formattedNumber(): string
    {
        $servicePost = $this->relationLoaded('servicePost')
            ? $this->getRelation('servicePost')
            : $this->servicePost()->first();

        if ($servicePost instanceof ServicePost) {
            if ($servicePost->behavior === ServicePostBehavior::DonationForm
                || in_array($this->queue_type, [
                    QueueType::DonorGlobal,
                    QueueType::MaleDonor,
                    QueueType::FemaleDonor,
                ], true)
            ) {
                $event = $this->relationLoaded('event')
                    ? $this->event
                    : $this->event()->with('settings')->firstOrFail();
                $settings = $this->settingsFor($event);

                return $settings->donorPrefix($this->queue_type)
                    .str_pad((string) $this->number, $settings->donor_queue_digits, '0', STR_PAD_LEFT);
            }

            // Posts created by the workflow builder use their own prefix and
            // digit contract. A null prefix on a legacy typed post means the
            // old event-level queue settings still own its presentation.
            if ($servicePost->queue_prefix === null
                && $servicePost->type !== ServicePostType::Custom
            ) {
                $event = $this->relationLoaded('event')
                    ? $this->event
                    : $this->event()->with('settings')->firstOrFail();
                $settings = $this->settingsFor($event);

                return str_pad((string) $this->number, $settings->general_queue_digits, '0', STR_PAD_LEFT);
            }

            return ($servicePost->queue_prefix ?? '')
                .str_pad((string) $this->number, $servicePost->queue_number_digits, '0', STR_PAD_LEFT);
        }

        $event = $this->relationLoaded('event')
            ? $this->event
            : $this->event()->with('settings')->firstOrFail();
        $settings = $this->settingsFor($event);

        $prefix = match ($this->queue_type) {
            QueueType::General => '',
            QueueType::DonorGlobal => $settings->donor_queue_prefix,
            QueueType::MaleDonor => $settings->male_donor_queue_prefix,
            QueueType::FemaleDonor => $settings->female_donor_queue_prefix,
        };
        $digits = $this->queue_type === QueueType::General
            ? $settings->general_queue_digits
            : $settings->donor_queue_digits;

        return $prefix.str_pad((string) $this->number, $digits, '0', STR_PAD_LEFT);
    }

    private function settingsFor(Event $event): EventSetting
    {
        if ($event->relationLoaded('settings')) {
            $settings = $event->getRelation('settings');

            if ($settings instanceof EventSetting) {
                return $settings;
            }
        }

        return $event->settings()->first() ?? new EventSetting([
            'general_queue_digits' => 3,
            'registration_number_format' => RegistrationNumberFormat::GenderPrefix,
            'registration_queue_prefix' => 'R',
            'registration_male_prefix' => 'L',
            'registration_female_prefix' => 'P',
            'registration_queue_digits' => 3,
            'donor_number_mode' => DonorNumberMode::Global,
            'donor_queue_prefix' => 'D',
            'donor_queue_digits' => 3,
            'male_donor_queue_prefix' => 'L',
            'female_donor_queue_prefix' => 'P',
        ]);
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
     * @return BelongsTo<ServicePost, $this>
     */
    public function servicePost(): BelongsTo
    {
        return $this->belongsTo(ServicePost::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function calledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'called_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
