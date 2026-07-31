<?php

namespace App\Http\Controllers\Donation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Donation\DonationQueueActionRequest;
use App\Http\Resources\DonorQueueTicketResource;
use App\Models\Event;
use App\Models\QueueTicket;
use App\Models\ServicePost;
use App\Services\Donation\DonorQueueService;
use App\Services\Workflow\ServiceQueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\View\View;

class DonorQueueController extends Controller
{
    public function index(Event $event, DonorQueueService $donorQueueService): View
    {
        $this->authorize('viewDonationQueue', QueueTicket::class);
        $event->load('settings');
        $tickets = $donorQueueService->ticketsForEvent($event);

        return view('donation.index', [
            'event' => $event,
            'ticketsJson' => DonorQueueTicketResource::collection($tickets)->resolve(),
        ]);
    }

    public function data(
        Event $event,
        DonorQueueService $donorQueueService,
    ): AnonymousResourceCollection {
        $this->authorize('viewDonationQueue', QueueTicket::class);

        return DonorQueueTicketResource::collection($donorQueueService->ticketsForEvent($event));
    }

    public function call(
        DonationQueueActionRequest $request,
        Event $event,
        QueueTicket $queueTicket,
        DonorQueueService $donorQueueService,
    ): JsonResponse|RedirectResponse {
        $ticket = $donorQueueService->call($event, $queueTicket, $request->user());
        $message = "Nomor {$ticket->formattedNumber()} sedang dipanggil.";

        return $this->actionResponse($request, $ticket, $message);
    }

    public function start(
        DonationQueueActionRequest $request,
        Event $event,
        QueueTicket $queueTicket,
        DonorQueueService $donorQueueService,
    ): JsonResponse|RedirectResponse {
        $ticket = $donorQueueService->start($event, $queueTicket, $request->user());
        $message = "Donor nomor {$ticket->formattedNumber()} dimulai.";

        return $this->actionResponse($request, $ticket, $message);
    }

    public function skip(
        DonationQueueActionRequest $request,
        Event $event,
        QueueTicket $queueTicket,
        DonorQueueService $donorQueueService,
    ): JsonResponse|RedirectResponse {
        $ticket = $donorQueueService->skip($event, $queueTicket, $request->user());
        $message = "Nomor {$ticket->formattedNumber()} dilewati dan dapat dipanggil kembali.";

        return $this->actionResponse($request, $ticket, $message);
    }

    public function cancel(
        DonationQueueActionRequest $request,
        Event $event,
        QueueTicket $queueTicket,
        ServiceQueueService $serviceQueueService,
    ): JsonResponse|RedirectResponse {
        $servicePost = ServicePost::query()
            ->where('event_id', $event->id)
            ->whereKey($queueTicket->service_post_id)
            ->firstOrFail();
        $ticket = $serviceQueueService->cancel(
            $event,
            $servicePost,
            $queueTicket,
            $request->user(),
        );
        $message = "Nomor {$ticket->formattedNumber()} dibatalkan dan telah dilepas.";

        return $this->actionResponse($request, $ticket, $message);
    }

    public function complete(
        DonationQueueActionRequest $request,
        Event $event,
        QueueTicket $queueTicket,
        DonorQueueService $donorQueueService,
    ): JsonResponse|RedirectResponse {
        $ticket = $donorQueueService->complete($event, $queueTicket, $request->user());
        $message = "Donor nomor {$ticket->formattedNumber()} selesai.";

        return $this->actionResponse($request, $ticket, $message);
    }

    private function actionResponse(
        DonationQueueActionRequest $request,
        QueueTicket $ticket,
        string $message,
    ): JsonResponse|RedirectResponse {
        if ($request->expectsJson()) {
            $ticket->loadMissing([
                'event.settings',
                'eventParticipant.participant',
                'calledBy',
                'servicePost',
            ]);

            return response()->json([
                'message' => $message,
                'data' => (new DonorQueueTicketResource($ticket))->resolve($request),
            ]);
        }

        return back()->with('status', $message);
    }
}
