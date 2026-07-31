<?php

namespace App\Http\Controllers\CheckIn;

use App\Http\Controllers\Controller;
use App\Http\Resources\QueueTicketResource;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\QueueTicket;
use App\Services\Workflow\WorkflowDefinitionService;
use Illuminate\Http\JsonResponse;

class CheckInDataController extends Controller
{
    public function __invoke(
        Event $event,
        WorkflowDefinitionService $workflowService,
    ): JsonResponse {
        $this->authorize('viewAny', [EventParticipant::class, $event]);

        $tickets = QueueTicket::query()
            ->where('event_id', $event->id)
            ->whereIn('id', QueueTicket::query()
                ->selectRaw('MIN(id)')
                ->where('event_id', $event->id)
                ->groupBy('event_participant_id'))
            ->with(['event.settings', 'eventParticipant.participant', 'eventParticipant.services', 'servicePost'])
            ->latest('id')
            ->limit(20)
            ->get();
        $workflow = $workflowService->validate($event);

        return response()->json([
            'data' => QueueTicketResource::collection($tickets)->resolve(),
            'meta' => [
                'workflow' => $workflow->toRegistrationState($event->status),
            ],
        ]);
    }
}
