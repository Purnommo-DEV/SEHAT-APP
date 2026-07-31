<?php

namespace App\Http\Resources;

use App\Models\EventParticipant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EventParticipant
 */
class ScreeningParticipantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $screening = $this->latestDonorScreening;

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'participant' => [
                'id' => $this->participant->id,
                'name' => $this->participant->name,
                'gender' => $this->participant->gender->value,
                'gender_label' => $this->participant->gender->label(),
                'phone' => $this->participant->phone,
            ],
            'health' => $this->healthAssessment === null ? null : [
                'blood_pressure' => $this->healthAssessment->blood_pressure,
                'blood_sugar' => $this->healthAssessment->blood_sugar,
                'cholesterol' => $this->healthAssessment->cholesterol,
                'uric_acid' => $this->healthAssessment->uric_acid,
                'notes' => $this->healthAssessment->notes,
            ],
            'screening' => $screening === null ? null : [
                'result' => $screening->result->value,
                'result_label' => $screening->result->label(),
                'reason' => $screening->reason,
                'screened_by' => $screening->screenedBy->name,
                'created_at' => $screening->created_at?->toIso8601String(),
            ],
            'urls' => [
                'store' => route('events.screening.store', [$this->event_id, $this->resource]),
            ],
        ];
    }
}
