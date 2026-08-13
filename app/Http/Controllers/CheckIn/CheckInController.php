<?php

namespace App\Http\Controllers\CheckIn;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckIn\StoreCheckInRequest;
use App\Http\Requests\CheckIn\UpdateCheckInServicesRequest;
use App\Http\Resources\QueueTicketResource;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\Participant;
use App\Models\QueueTicket;
use App\Services\CheckIn\CheckInService;
use App\Services\CheckIn\RecentCheckInTicketService;
use App\Services\Workflow\WorkflowDefinitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CheckInController extends Controller
{
    public function index(
        Event $event,
        WorkflowDefinitionService $workflowService,
        RecentCheckInTicketService $recentTickets,
    ): View {
        $event->load('settings');
        $workflow = $workflowService->validate($event);
        $tickets = $recentTickets->forEvent($event);

        return view('check-ins.index', [
            'event' => $event,
            'ticketsJson' => QueueTicketResource::collection($tickets)->resolve(),
            'workflow' => $workflow,
            'workflowJson' => $workflow->toRegistrationState($event->status),
        ]);
    }

    public function store(
        StoreCheckInRequest $request,
        Event $event,
        CheckInService $checkInService,
    ): JsonResponse|RedirectResponse {
        $participant = Participant::query()->findOrFail($request->integer('participant_id'));
        $result = $checkInService->checkIn(
            $event,
            $participant,
            $request->user(),
            $request->validated('services'),
        );

        $message = $result->alreadyCheckedIn
            ? "Peserta sudah check-in dengan nomor registrasi {$result->registrationNumber}."
            : "Check-in berhasil. Nomor registrasi {$result->registrationNumber}.";

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'data' => (new QueueTicketResource($result->queueTicket))->resolve($request),
            ], $result->alreadyCheckedIn ? 200 : 201);
        }

        return redirect()
            ->route('events.check-ins.show', [$event, $result->eventParticipant])
            ->with('status', $message);
    }

    public function show(Event $event, EventParticipant $eventParticipant): View|RedirectResponse
    {
        $event->load('settings');
        $eventParticipant->load(['participant', 'currentServicePost', 'checkedInBy', 'services']);
        $queueTicket = $eventParticipant->queueTickets()
            ->with(['event.settings', 'eventParticipant.participant', 'eventParticipant.services', 'servicePost'])
            ->oldest('id')
            ->first();

        if (! $queueTicket instanceof QueueTicket) {
            return redirect()
                ->route('events.check-ins.index', $event)
                ->with('status', 'Registrasi peserta belum memiliki tiket antrean. Kirim ulang check-in untuk memulihkan tiket awal.');
        }

        return view('check-ins.show', compact('event', 'eventParticipant', 'queueTicket'));
    }

    public function update(
        UpdateCheckInServicesRequest $request,
        Event $event,
        EventParticipant $eventParticipant,
        CheckInService $checkInService,
    ): JsonResponse|RedirectResponse {
        $this->authorize('updateRegistration', $eventParticipant);

        $queueTicket = $checkInService->updateServices(
            $event,
            $eventParticipant,
            $request->user(),
            $request->serviceTypes(),
        );

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Pilihan layanan peserta berhasil diperbarui.',
                'data' => (new QueueTicketResource($queueTicket))->resolve($request),
            ]);
        }

        return redirect()
            ->route('events.check-ins.index', $event)
            ->with('status', 'Pilihan layanan peserta berhasil diperbarui.');
    }

    public function cancel(
        Request $request,
        Event $event,
        EventParticipant $eventParticipant,
        CheckInService $checkInService,
    ): JsonResponse|RedirectResponse {
        $this->authorize('cancel', $eventParticipant);

        $cancelledParticipant = $checkInService->cancel($event, $eventParticipant, $request->user());
        $message = 'Registrasi dibatalkan. Nomor aktif telah dilepas dan dapat digunakan kembali.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'data' => [
                    'id' => $cancelledParticipant->id,
                    'status' => $cancelledParticipant->status->value,
                    'cancelled_at' => $cancelledParticipant->cancelled_at?->toIso8601String(),
                ],
            ]);
        }

        return redirect()
            ->route('events.check-ins.index', $event)
            ->with('status', $message);
    }
}
