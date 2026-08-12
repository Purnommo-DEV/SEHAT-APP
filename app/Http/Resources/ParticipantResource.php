<?php

namespace App\Http\Resources;

use App\Enums\ParticipantStatus;
use App\Models\EventParticipant;
use App\Models\EventSetting;
use App\Models\Participant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Participant
 */
class ParticipantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $registration = $this->resource->relationLoaded('registrationForEvent')
            ? $this->resource->getRelation('registrationForEvent')
            : null;
        $settings = $this->resource->relationLoaded('registrationEventSettings')
            ? $this->resource->getRelation('registrationEventSettings')
            : null;
        $isRegistered = $registration instanceof EventParticipant && $registration->checked_in_at !== null;
        $isFinished = $registration instanceof EventParticipant && $registration->status === ParticipantStatus::Finished;

        $data = [
            'id' => $this->id,
            'nik' => $this->nik,
            'name' => $this->name,
            'phone' => $this->phone,
            'gender' => $this->gender->value,
            'gender_label' => $this->gender->label(),
            'birth_date' => $this->birth_date?->toDateString(),
            'address' => $this->address,
            'urls' => [
                'edit' => route('participants.edit', $this->resource),
                'destroy' => route('participants.destroy', $this->resource),
            ],
        ];

        if (! $registration instanceof EventParticipant || ! $settings instanceof EventSetting) {
            return $data;
        }

        $data['registration'] = [
            'is_available' => ! $isRegistered,
            'is_registered' => $isRegistered,
            'is_finished' => $isFinished,
            'status' => $registration->status->value,
            'status_label' => $isFinished ? 'Selesai' : 'Sudah terdaftar',
            'registration_number' => $registration->formattedRegistrationNumber($settings),
            'registration_order' => $registration->registration_order,
        ];

        return $data;
    }
}
