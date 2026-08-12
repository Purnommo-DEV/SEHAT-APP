<?php

namespace App\Http\Controllers\ServiceQueue;

use App\Enums\ParticipantGender;
use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceQueue\CompleteServicePostRequest;
use App\Http\Requests\ServiceQueue\GotoServiceQueueRequest;
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
use Illuminate\Validation\ValidationException;

class ServiceQueueController extends Controller
{
    public function index(
        Event $event,
        ServicePost $servicePost,
        ServicePostAccessService $accessService,
    ): RedirectResponse {
        $this->ensureAccess($event, $servicePost, $accessService);

        return redirect()->route('events.operations.waiting.desk', $event);
    }

    public function data(
        Event $event,
        ServicePost $servicePost,
        ServiceQueueService $queueService,
        ServicePostAccessService $accessService,
    ): AnonymousResourceCollection {
        $this->ensureAccess($event, $servicePost, $accessService);

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
        $this->ensureAccess($event, $servicePost, $accessService);
        $ticket = $queueService->call($event, $servicePost, $queueTicket, $request->user());

        return $this->respond($request, $event, $servicePost, $ticket, 'Nomor antrean berhasil dipanggil.');
    }

    public function goto(
        GotoServiceQueueRequest $request,
        Event $event,
        ServicePost $servicePost,
        QueueTicket $queueTicket,
        ServiceQueueService $queueService,
        ServicePostAccessService $accessService,
    ): JsonResponse|RedirectResponse {
        $this->ensureAccess($event, $servicePost, $accessService);

        $queueTicket->loadMissing('eventParticipant.participant');
        $lane = $request->enum('queue_lane', ParticipantGender::class);

        if ($lane instanceof ParticipantGender
            && $queueTicket->eventParticipant->participant->gender !== $lane
        ) {
            throw ValidationException::withMessages([
                'queue_ticket' => 'Peserta yang dipilih bukan bagian dari jalur antrean yang dipilih.',
            ]);
        }

        $ticket = $queueService->call($event, $servicePost, $queueTicket, $request->user());

        return $this->respond($request, $event, $servicePost, $ticket, 'Nomor antrean dipanggil melalui Goto.');
    }

    public function start(
        Request $request,
        Event $event,
        ServicePost $servicePost,
        QueueTicket $queueTicket,
        ServiceQueueService $queueService,
        ServicePostAccessService $accessService,
    ): JsonResponse|RedirectResponse {
        $this->ensureAccess($event, $servicePost, $accessService);
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
        $this->ensureAccess($event, $servicePost, $accessService);
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
        $this->ensureAccess($event, $servicePost, $accessService);
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
        $this->ensureAccess($event, $servicePost, $accessService);
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

    private function ensureAccess(Event $event, ServicePost $servicePost, ServicePostAccessService $accessService): void
    {
        abort_unless($servicePost->event_id === $event->id && $servicePost->is_active, 404);

        if (request()->user() === null) {
            return;
        }

        abort_unless($accessService->canManage($servicePost, request()->user()), 403);
    }

    private function back(Event $event, ServicePost $servicePost, string $message): RedirectResponse
    {
        return redirect()
            ->route('events.operations.waiting.desk', $event)
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
