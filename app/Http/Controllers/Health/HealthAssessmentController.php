<?php

namespace App\Http\Controllers\Health;

use App\Enums\ServicePostBehavior;
use App\Http\Controllers\Controller;
use App\Http\Requests\Health\SaveHealthAssessmentRequest;
use App\Models\Event;
use App\Models\HealthAssessment;
use App\Models\QueueTicket;
use App\Services\Health\HealthQueueService;
use App\Services\Workflow\LegacyServicePostBehaviorResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class HealthAssessmentController extends Controller
{
    public function edit(
        Event $event,
        QueueTicket $queueTicket,
        LegacyServicePostBehaviorResolver $legacyPostBehavior,
    ): View {
        $this->authorize('create', HealthAssessment::class);
        $queueTicket->load([
            'event.settings',
            'eventParticipant.participant',
            'eventParticipant.healthAssessment',
            'servicePost',
        ]);

        abort_unless($legacyPostBehavior->matches($queueTicket->servicePost, ServicePostBehavior::HealthForm), 404);

        return view('health.assessment', [
            'event' => $event,
            'queueTicket' => $queueTicket,
            'eventParticipant' => $queueTicket->eventParticipant,
            'healthAssessment' => $queueTicket->eventParticipant->healthAssessment,
        ]);
    }

    public function store(
        SaveHealthAssessmentRequest $request,
        Event $event,
        QueueTicket $queueTicket,
        HealthQueueService $healthQueueService,
    ): JsonResponse|RedirectResponse {
        $result = $healthQueueService->complete(
            $event,
            $queueTicket,
            $request->validated(),
            $request->user(),
        );

        $message = $result->alreadyCompleted
            ? 'Pemeriksaan peserta ini sebelumnya sudah diselesaikan.'
            : 'Pemeriksaan kesehatan peserta selesai.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'redirect_url' => route('events.health.index', $event),
            ]);
        }

        return redirect()->route('events.health.index', $event)->with('status', $message);
    }

    public function update(
        SaveHealthAssessmentRequest $request,
        Event $event,
        HealthAssessment $healthAssessment,
        HealthQueueService $healthQueueService,
    ): JsonResponse|RedirectResponse {
        $healthQueueService->update($event, $healthAssessment, $request->validated(), $request->user());
        $message = 'Hasil pemeriksaan berhasil diperbarui.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message]);
        }

        return redirect()
            ->route('events.health.index', $event)
            ->with('status', $message);
    }
}
