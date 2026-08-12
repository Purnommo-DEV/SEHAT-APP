<?php

namespace App\Http\Controllers\Screening;

use App\Http\Controllers\Controller;
use App\Http\Requests\Screening\StoreScreeningRequest;
use App\Http\Resources\ScreeningParticipantResource;
use App\Models\DonorScreening;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Services\Screening\DonorScreeningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DonorScreeningController extends Controller
{
    public function index(Event $event): RedirectResponse
    {
        $this->authorize('viewAny', DonorScreening::class);

        return redirect()->route('events.operations.health-check', $event);
    }

    public function data(
        Event $event,
        DonorScreeningService $screeningService,
    ): AnonymousResourceCollection {
        $this->authorize('viewAny', DonorScreening::class);

        return ScreeningParticipantResource::collection($screeningService->participantsForEvent($event));
    }

    public function store(
        StoreScreeningRequest $request,
        Event $event,
        EventParticipant $eventParticipant,
        DonorScreeningService $screeningService,
    ): JsonResponse|RedirectResponse {
        $decision = $screeningService->decide(
            $event,
            $eventParticipant,
            $request->validated(),
            $request->user(),
        );
        if ($decision->alreadyDecided) {
            $message = 'Hasil screening peserta ini sebelumnya sudah dicatat.';
        } elseif ($decision->donorQueueTicket !== null) {
            $message = "Layak donor. Nomor antrean {$decision->donorQueueTicket->formattedNumber()} diterbitkan.";
        } else {
            $message = "Hasil screening: {$decision->screening->result->label()}.";
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => $message]);
        }

        return redirect()->route('events.screening.index', $event)->with('status', $message);
    }
}
