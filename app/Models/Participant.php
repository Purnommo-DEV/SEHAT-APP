<?php

namespace App\Models;

use App\Enums\ParticipantGender;
use Database\Factories\ParticipantFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $nik
 * @property string $name
 * @property string|null $phone
 * @property ParticipantGender $gender
 * @property Carbon|null $birth_date
 * @property string|null $address
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Participant extends Model
{
    /** @use HasFactory<ParticipantFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'nik',
        'name',
        'phone',
        'gender',
        'birth_date',
        'address',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gender' => ParticipantGender::class,
            'birth_date' => 'date',
        ];
    }

    /**
     * @param  Builder<Participant>  $query
     * @return Builder<Participant>
     */
    public function scopeMatching(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $query) use ($term): void {
            $query->where('name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('nik', 'like', "%{$term}%");
        });
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
    public function eventParticipants(): HasMany
    {
        return $this->hasMany(EventParticipant::class);
    }
}
