<?php

namespace App\Http\Resources;

use App\Models\ServicePost;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServicePost
 */
class ServicePostResource extends JsonResource
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
            'behavior' => $this->behavior->value,
            'behavior_label' => $this->behavior->label(),
            'queue_prefix' => $this->queue_prefix,
            'queue_number_digits' => $this->queue_number_digits,
            'sequence' => $this->sequence,
            'is_active' => $this->is_active,
            'urls' => [
                'edit' => route('events.service-posts.edit', [$this->event_id, $this->resource]),
                'move' => route('events.service-posts.move', [$this->event_id, $this->resource]),
                'toggle' => route('events.service-posts.toggle', [$this->event_id, $this->resource]),
                'destroy' => route('events.service-posts.destroy', [$this->event_id, $this->resource]),
            ],
        ];
    }
}
