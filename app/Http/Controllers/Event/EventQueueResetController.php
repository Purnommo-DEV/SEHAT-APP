<?php

namespace App\Http\Controllers\Event;

use App\Http\Controllers\Controller;
use App\Http\Requests\Event\ResetEventQueueRequest;
use App\Models\Event;
use App\Services\Event\EventQueueResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class EventQueueResetController extends Controller
{
    public function store(
        ResetEventQueueRequest $request,
        Event $event,
        EventQueueResetService $queueReset,
    ): JsonResponse|RedirectResponse {
        $summary = $queueReset->reset($event, $request->user());
        $message = $summary->isEmpty()
            ? 'Antrean event sudah kosong.'
            : 'Antrean event berhasil direset.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'data' => $summary->toArray(),
            ]);
        }

        return redirect()
            ->route('events.settings.edit', $event)
            ->with('status', $message);
    }
}
