<?php

namespace App\Http\Controllers\Monitor;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\Monitor\MonitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class MonitorController extends Controller
{
    public function show(Event $event, MonitorService $monitorService): View
    {
        abort_unless($event->active_marker === 'active', 404);

        return view('monitor.show', [
            'event' => $event,
            'snapshot' => $monitorService->snapshot(),
            'dataUrl' => route('events.monitor.data', $event),
        ]);
    }

    public function data(Event $event, MonitorService $monitorService): JsonResponse
    {
        abort_unless($event->active_marker === 'active', 404);

        return response()->json($monitorService->snapshot());
    }
}
