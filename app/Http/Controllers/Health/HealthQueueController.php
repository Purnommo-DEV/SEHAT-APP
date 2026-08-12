<?php

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use App\Http\Requests\Health\HealthQueueActionRequest;
use App\Http\Resources\HealthQueueTicketResource;
use App\Models\Event;
use App\Models\HealthAssessment;
use App\Models\QueueTicket;
use App\Services\Health\HealthQueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class HealthQueueController extends Controller
{
    public function index(Event $event): RedirectResponse
    {
        $this->authorize('viewAny', HealthAssessment::class);

        return redirect()->route('events.operations.health-check', $event);
    }

    public function data(
        Event $event,
        HealthQueueService $healthQueueService,
    ): AnonymousResourceCollection {
        $this->authorize('viewAny', HealthAssessment::class);

        return HealthQueueTicketResource::collection($healthQueueService->ticketsForEvent($event));
    }

    public function call(
        HealthQueueActionRequest $request,
        Event $event,
        QueueTicket $queueTicket,
        HealthQueueService $healthQueueService,
    ): JsonResponse|RedirectResponse {
        $ticket = $healthQueueService->call($event, $queueTicket, $request->user());
        $message = "Nomor {$ticket->formattedNumber()} sedang dipanggil.";

        return $this->actionResponse($request, $ticket, $message);
    }

    public function start(
        HealthQueueActionRequest $request,
        Event $event,
        QueueTicket $queueTicket,
        HealthQueueService $healthQueueService,
    ): JsonResponse|RedirectResponse {
        $ticket = $healthQueueService->start($event, $queueTicket, $request->user());
        $message = "Pelayanan nomor {$ticket->formattedNumber()} dimulai.";
        $redirectUrl = route('events.health.assessments.edit', [$event, $ticket]);

        if ($request->expectsJson()) {
            $ticket->loadMissing([
                'event.settings',
                'eventParticipant.participant',
                'eventParticipant.healthAssessment',
                'calledBy',
                'servicePost',
            ]);

            return response()->json([
                'message' => $message,
                'redirect_url' => $redirectUrl,
                'data' => (new HealthQueueTicketResource($ticket))->resolve($request),
            ]);
        }

        return redirect()->to($redirectUrl)->with('status', $message);
    }

    public function skip(
        HealthQueueActionRequest $request,
        Event $event,
        QueueTicket $queueTicket,
        HealthQueueService $healthQueueService,
    ): JsonResponse|RedirectResponse {
        $ticket = $healthQueueService->skip($event, $queueTicket, $request->user());
        $message = "Nomor {$ticket->formattedNumber()} dilewati dan dapat dipanggil kembali.";

        return $this->actionResponse($request, $ticket, $message);
    }

    private function actionResponse(
        HealthQueueActionRequest $request,
        QueueTicket $ticket,
        string $message,
    ): JsonResponse|RedirectResponse {
        if ($request->expectsJson()) {
            $ticket->loadMissing([
                'event.settings',
                'eventParticipant.participant',
                'eventParticipant.healthAssessment',
                'calledBy',
                'servicePost',
            ]);

            return response()->json([
                'message' => $message,
                'data' => (new HealthQueueTicketResource($ticket))->resolve($request),
            ]);
        }

        return back()->with('status', $message);
    }
}
