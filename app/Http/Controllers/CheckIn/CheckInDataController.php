<?php

namespace App\Http\Controllers\CheckIn;

use App\Http\Controllers\Controller;
use App\Http\Resources\QueueTicketResource;
use App\Models\Event;
use App\Services\CheckIn\RecentCheckInTicketService;
use App\Services\Workflow\WorkflowDefinitionService;
use Illuminate\Http\JsonResponse;

class CheckInDataController extends Controller
{
    public function __invoke(
        Event $event,
        WorkflowDefinitionService $workflowService,
        RecentCheckInTicketService $recentTickets,
    ): JsonResponse {
        $tickets = $recentTickets->forEvent($event);
        $workflow = $workflowService->validate($event);

        return response()->json([
            'data' => QueueTicketResource::collection($tickets)->resolve(),
            'meta' => [
                'workflow' => $workflow->toRegistrationState($event->status),
            ],
        ]);
    }
}
