<?php

namespace App\Http\Resources;

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
        return [
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
    }
}
