<?php

namespace App\Models;

use App\Enums\DonorNumberMode;
use App\Enums\ParticipantGender;
use App\Enums\QueueType;
use App\Enums\RegistrationNumberFormat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $event_id
 * @property RegistrationNumberFormat $registration_number_format
 * @property string $registration_queue_prefix
 * @property string $registration_male_prefix
 * @property string $registration_female_prefix
 * @property int $registration_queue_digits
 * @property DonorNumberMode $donor_number_mode
 * @property string $donor_queue_prefix
 * @property int $donor_queue_digits
 * @property int $donation_capacity_male
 * @property int $donation_capacity_female
 *
 * @deprecated Kept only for backward compatibility with legacy integrations.
 *
 * @property int $donation_capacity
 * @property int $general_queue_digits
 * @property string $male_donor_queue_prefix
 * @property string $female_donor_queue_prefix
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EventSetting extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'registration_queue_prefix',
        'registration_number_format',
        'registration_male_prefix',
        'registration_female_prefix',
        'registration_queue_digits',
        'donor_number_mode',
        'donor_queue_prefix',
        'donor_queue_digits',
        'donation_capacity_male',
        'donation_capacity_female',
        'donation_capacity',
        'general_queue_digits',
        'male_donor_queue_prefix',
        'female_donor_queue_prefix',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'registration_number_format' => RegistrationNumberFormat::class,
            'donor_number_mode' => DonorNumberMode::class,
            'donation_capacity_male' => 'integer',
            'donation_capacity_female' => 'integer',
            'donation_capacity' => 'integer',
        ];
    }

    public function registrationPrefix(ParticipantGender $gender): string
    {
        return match ($gender) {
            ParticipantGender::Male => $this->registration_male_prefix,
            ParticipantGender::Female => $this->registration_female_prefix,
        };
    }

    public static function formatQueueNumber(?string $prefix, int $number, int $digits): string
    {
        $formattedNumber = str_pad((string) $number, $digits, '0', STR_PAD_LEFT);
        $normalizedPrefix = rtrim(trim((string) $prefix), '-');

        return $normalizedPrefix === ''
            ? $formattedNumber
            : "{$normalizedPrefix}-{$formattedNumber}";
    }

    public function donorQueueType(ParticipantGender $gender): QueueType
    {
        if ($this->donor_number_mode === DonorNumberMode::Global) {
            return QueueType::DonorGlobal;
        }

        return QueueType::forDonorGender($gender);
    }

    public function donorPrefix(QueueType $queueType): string
    {
        return match ($queueType) {
            QueueType::MaleDonor => $this->male_donor_queue_prefix,
            QueueType::FemaleDonor => $this->female_donor_queue_prefix,
            default => $this->donor_queue_prefix,
        };
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
