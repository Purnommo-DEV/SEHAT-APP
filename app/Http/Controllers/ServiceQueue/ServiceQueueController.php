<?php

namespace App\Http\Controllers\ServiceQueue;

use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceQueue\CompleteServicePostRequest;
use App\Http\Resources\ServiceQueueTicketResource;
use App\Models\Event;
use App\Models\QueueTicket;
use App\Models\ServicePost;
use App\Services\Workflow\ServicePostAccessService;
use App\Services\Workflow\ServiceQueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\View\View;

class ServiceQueueController extends Controller
{
    public function index(
        Event $event,
        ServicePost $servicePost,
        ServiceQueueService $queueService,
        ServicePostAccessService $accessService,
    ): View {
        $this->ensureAccess($servicePost, $accessService);
        $tickets = $queueService->ticketsForPost($event, $servicePost);

        return view('service-queues.index', [
            'event' => $event,
            'servicePost' => $servicePost->load('operators:id,name'),
            'ticketsJson' => ServiceQueueTicketResource::collection($tickets)->resolve(),
            'dataUrl' => route('events.service-queues.data', [$event, $servicePost]),
        ]);
    }

    public function data(
        Event $event,
        ServicePost $servicePost,
        ServiceQueueService $queueService,
        ServicePostAccessService $accessService,
    ): AnonymousResourceCollection {
        $this->ensureAccess($servicePost, $accessService);

        return ServiceQueueTicketResource::collection($queueService->ticketsForPost($event, $servicePost));
    }

    public function call(
        Request $request,
        Event $event,
        ServicePost $servicePost,
        QueueTicket $queueTicket,
        ServiceQueueService $queueService,
        ServicePostAccessService $accessService,
    ): JsonResponse|RedirectResponse {
        $this->ensureAccess($servicePost, $accessService);
        $ticket = $queueService->call($event, $servicePost, $queueTicket, $request->user());

        return $this->respond($request, $event, $servicePost, $ticket, 'Nomor antrean berhasil dipanggil.');
    }

    public function start(
        Request $request,
        Event $event,
        ServicePost $servicePost,
        QueueTicket $queueTicket,
        ServiceQueueService $queueService,
        ServicePostAccessService $accessService,
    ): JsonResponse|RedirectResponse {
        $this->ensureAccess($servicePost, $accessService);
        $ticket = $queueService->start($event, $servicePost, $queueTicket, $request->user());

        return $this->respond($request, $event, $servicePost, $ticket, 'Pelayanan dimulai.');
    }

    public function skip(
        Request $request,
        Event $event,
        ServicePost $servicePost,
        QueueTicket $queueTicket,
        ServiceQueueService $queueService,
        ServicePostAccessService $accessService,
    ): JsonResponse|RedirectResponse {
        $this->ensureAccess($servicePost, $accessService);
        $ticket = $queueService->skip($event, $servicePost, $queueTicket, $request->user());

        return $this->respond($request, $event, $servicePost, $ticket, 'Nomor antrean dilewati.');
    }

    public function cancel(
        Request $request,
        Event $event,
        ServicePost $servicePost,
        QueueTicket $queueTicket,
        ServiceQueueService $queueService,
        ServicePostAccessService $accessService,
    ): JsonResponse|RedirectResponse {
        $this->ensureAccess($servicePost, $accessService);
        $ticket = $queueService->cancel($event, $servicePost, $queueTicket, $request->user());

        return $this->respond($request, $event, $servicePost, $ticket, 'Nomor antrean dibatalkan dan telah dilepas.');
    }

    public function complete(
        CompleteServicePostRequest $request,
        Event $event,
        ServicePost $servicePost,
        QueueTicket $queueTicket,
        ServiceQueueService $queueService,
        ServicePostAccessService $accessService,
    ): JsonResponse|RedirectResponse {
        $this->ensureAccess($servicePost, $accessService);
        $ticket = $queueService->complete(
            $event,
            $servicePost,
            $queueTicket,
            $request->validated(),
            $request->user(),
        );

        return $this->respond(
            $request,
            $event,
            $servicePost,
            $ticket,
            'Pelayanan selesai. Tujuan peserta telah diperbarui.',
        );
    }

    private function ensureAccess(ServicePost $servicePost, ServicePostAccessService $accessService): void
    {
        abort_unless($accessService->canManage($servicePost, request()->user()), 403);
    }

    private function back(Event $event, ServicePost $servicePost, string $message): RedirectResponse
    {
        return redirect()
            ->route('events.service-queues.index', [$event, $servicePost])
            ->with('status', $message);
    }

    private function respond(
        Request $request,
        Event $event,
        ServicePost $servicePost,
        QueueTicket $ticket,
        string $message,
    ): JsonResponse|RedirectResponse {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'data' => [
                    'id' => $ticket->id,
                    'status' => $ticket->status->value,
                ],
            ]);
        }

        return $this->back($event, $servicePost, $message);
    }
}
