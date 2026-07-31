<?php

namespace App\Http\Controllers\Event;

use App\Http\Controllers\Controller;
use App\Http\Requests\Event\UpdateEventSettingsRequest;
use App\Models\Event;
use App\Services\Event\EventService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class EventSettingsController extends Controller
{
    public function edit(Event $event): View
    {
        $this->authorize('update', $event);

        $event->load('settings');

        return view('events.settings', compact('event'));
    }

    public function update(
        UpdateEventSettingsRequest $request,
        Event $event,
        EventService $eventService,
    ): RedirectResponse {
        $eventService->updateSettings($event, $request->validated(), $request->user());

        return redirect()
            ->route('events.show', $event)
            ->with('status', 'Pengaturan antrean event berhasil diperbarui.');
    }
}
