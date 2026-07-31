<?php

namespace App\Http\Controllers\Event;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\Event\EventService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EventStatusController extends Controller
{
    public function activate(Request $request, Event $event, EventService $eventService): RedirectResponse
    {
        $this->authorize('activate', $event);

        $eventService->activate($event, $request->user());

        return redirect()->route('events.index')->with('status', 'Event sekarang aktif untuk operasional.');
    }

    public function complete(Request $request, Event $event, EventService $eventService): RedirectResponse
    {
        $this->authorize('complete', $event);

        $eventService->complete($event, $request->user());

        return redirect()->route('events.index')->with('status', 'Event ditandai selesai dan antrean baru tidak dapat dibuat.');
    }

    public function cancel(Request $request, Event $event, EventService $eventService): RedirectResponse
    {
        $this->authorize('cancel', $event);

        $eventService->cancel($event, $request->user());

        return redirect()->route('events.index')->with('status', 'Event berhasil dibatalkan.');
    }
}
