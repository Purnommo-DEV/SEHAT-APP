<?php

namespace App\Http\Controllers\Event;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Models\Event;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EventDataController extends Controller
{
    public function __invoke(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Event::class);

        $events = Event::query()
            ->with('settings')
            ->orderByRaw("case when active_marker = 'active' then 0 else 1 end")
            ->orderByDesc('starts_at')
            ->get();

        return EventResource::collection($events);
    }
}
