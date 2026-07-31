<?php

namespace App\Http\Resources;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Event
 */
class EventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'location' => $this->location,
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_active' => $this->active_marker === 'active',
            'settings' => $this->whenLoaded('settings', fn (): array => [
                'registration_number_format' => $this->settings->registration_number_format->value,
                'registration_queue_prefix' => $this->settings->registration_queue_prefix,
                'registration_male_prefix' => $this->settings->registration_male_prefix,
                'registration_female_prefix' => $this->settings->registration_female_prefix,
                'registration_queue_digits' => $this->settings->registration_queue_digits,
                'donor_number_mode' => $this->settings->donor_number_mode->value,
                'donor_queue_prefix' => $this->settings->donor_queue_prefix,
                'donor_queue_digits' => $this->settings->donor_queue_digits,
                'general_queue_digits' => $this->settings->general_queue_digits,
                'male_donor_queue_prefix' => $this->settings->male_donor_queue_prefix,
                'female_donor_queue_prefix' => $this->settings->female_donor_queue_prefix,
            ]),
            'urls' => [
                'show' => route('events.show', $this->resource),
                'edit' => route('events.edit', $this->resource),
                'settings' => route('events.settings.edit', $this->resource),
            ],
        ];
    }
}
