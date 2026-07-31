<?php

namespace App\Data;

use App\Models\Event;

final readonly class EventReportSnapshot
{
    /**
     * @param  array<string, int>  $metrics
     * @param  list<array<string, mixed>>  $records
     */
    public function __construct(
        public Event $event,
        public array $metrics,
        public array $records,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event' => [
                'id' => $this->event->id,
                'code' => $this->event->code,
                'name' => $this->event->name,
                'location' => $this->event->location,
                'starts_at' => $this->event->starts_at->toIso8601String(),
                'ends_at' => $this->event->ends_at?->toIso8601String(),
            ],
            'metrics' => $this->metrics,
            'records' => $this->records,
        ];
    }
}
