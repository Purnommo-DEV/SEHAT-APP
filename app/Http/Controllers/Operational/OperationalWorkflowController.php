<?php

namespace App\Http\Controllers\Operational;

use App\Enums\ParticipantStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operational\OperationalWorkflowActionRequest;
use App\Http\Resources\OperationalParticipantResource;
use App\Http\Resources\ServiceQueueTicketResource;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Services\Operational\DonationCapacityService;
use App\Services\Operational\OperationalWorkflowService;
use App\Services\Workflow\ServiceQueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class OperationalWorkflowController extends Controller
{
    public function index(
        Event $event,
    ): RedirectResponse {
        return redirect()->route('events.operations.waiting.desk', $event);
    }

    public function waiting(Event $event): RedirectResponse
    {
        return redirect()->route('events.operations.waiting.desk', $event);
    }

    public function waitingDesk(
        Event $event,
        ServiceQueueService $queueService,
        OperationalWorkflowService $workflow,
        DonationCapacityService $donationCapacity,
    ): View {
        $event->load('settings');

        return view('operations.waiting-desk', [
            'event' => $event,
            'queueJson' => $this->screenPayload($event, $workflow, $queueService, $donationCapacity),
            'dataUrl' => route('events.operations.waiting.snapshot', $event),
            'canUpdateDonationCapacity' => auth()->user()?->can('updateDonationCapacity', $event) ?? false,
            'capacityUpdateUrl' => route('events.operations.donation-capacity.update', $event),
        ]);
    }

    public function snapshot(
        Event $event,
        OperationalWorkflowService $workflow,
        DonationCapacityService $donationCapacity,
        ServiceQueueService $queueService,
    ): JsonResponse {
        return response()->json($this->screenPayload($event, $workflow, $queueService, $donationCapacity));
    }

    public function waitingSnapshot(
        Event $event,
        ServiceQueueService $queueService,
        OperationalWorkflowService $workflow,
        DonationCapacityService $donationCapacity,
    ): JsonResponse {
        return response()->json($this->screenPayload($event, $workflow, $queueService, $donationCapacity));
    }

    /** Legacy stage URL retained for saved links; operational work is centralized on one screen. */
    public function healthCheck(Event $event): RedirectResponse
    {
        return redirect()->route('events.operations.waiting.desk', $event);
    }

    public function beforeDonation(Event $event): RedirectResponse
    {
        return redirect()->route('events.operations.waiting.desk', $event);
    }

    /** Legacy stage URL retained for saved links; operational work is centralized on one screen. */
    public function donating(Event $event): RedirectResponse
    {
        return redirect()->route('events.operations.waiting.desk', $event);
    }

    /** Legacy stage URL retained for saved links; operational work is centralized on one screen. */
    public function completed(Event $event): RedirectResponse
    {
        return redirect()->route('events.operations.waiting.desk', $event);
    }

    public function data(
        Event $event,
        string $stage,
        OperationalWorkflowService $workflow,
        DonationCapacityService $donationCapacity,
    ): JsonResponse {
        $status = $this->statusFor($stage);

        return response()->json([
            'data' => OperationalParticipantResource::collection($workflow->participantsForStage($event, $status))->resolve(),
            'meta' => [
                'donation_capacity' => $donationCapacity->snapshot($event)->toArray(),
            ],
        ]);
    }

    public function startHealthCheck(
        OperationalWorkflowActionRequest $request,
        Event $event,
        EventParticipant $eventParticipant,
        OperationalWorkflowService $workflow,
    ): JsonResponse|RedirectResponse {
        $participant = $workflow->startHealthCheck($event, $eventParticipant, $request->user());

        return $this->actionResponse($request, $participant, 'Peserta masuk tahap Cek Kesehatan.');
    }

    public function startDonation(
        OperationalWorkflowActionRequest $request,
        Event $event,
        EventParticipant $eventParticipant,
        OperationalWorkflowService $workflow,
    ): JsonResponse|RedirectResponse {
        $participant = $workflow->startDonation($event, $eventParticipant, $request->user());

        return $this->actionResponse($request, $participant, 'Peserta masuk proses donor.');
    }

    public function complete(
        OperationalWorkflowActionRequest $request,
        Event $event,
        EventParticipant $eventParticipant,
        OperationalWorkflowService $workflow,
    ): JsonResponse|RedirectResponse {
        $participant = $workflow->complete($event, $eventParticipant, $request->user());

        return $this->actionResponse($request, $participant, 'Peserta telah selesai.');
    }

    public function completeBeforeDonation(
        OperationalWorkflowActionRequest $request,
        Event $event,
        EventParticipant $eventParticipant,
        OperationalWorkflowService $workflow,
    ): JsonResponse|RedirectResponse {
        $participant = $workflow->completeBeforeDonation($event, $eventParticipant, $request->user());

        return $this->actionResponse($request, $participant, 'Peserta telah diselesaikan dari Area Cek Kesehatan.');
    }

    /**
     * @return array{
     *     stages: array<string, list<mixed>>,
     *     queue: array{post: array{id: int, name: string}|null, tickets: list<mixed>, positions: list<mixed>},
     *     donation_capacity: array<string, mixed>
     * }
     */
    private function screenPayload(
        Event $event,
        OperationalWorkflowService $workflow,
        ServiceQueueService $queueService,
        DonationCapacityService $donationCapacity,
    ): array {
        $stages = [];

        foreach ($workflow->participantsForOperationalScreen($event) as $status => $participants) {
            $stages[$status] = array_values(
                OperationalParticipantResource::collection($participants)->resolve(),
            );
        }

        $queuePost = $queueService->controlPostForWaitingArea($event);

        return [
            'stages' => $stages,
            'donation_capacity' => $donationCapacity->snapshot($event)->toArray(),
            'queue' => $this->waitingPayload($event, $queueService, $workflow),
        ];
    }

    /**
     * @return array{post: array{id: int, name: string}|null, tickets: list<mixed>, positions: list<mixed>}
     */
    private function waitingPayload(
        Event $event,
        ServiceQueueService $queueService,
        OperationalWorkflowService $workflow,
    ): array {
        $queuePost = $queueService->controlPostForWaitingArea($event);

        return [
            'post' => $queuePost === null
                ? null
                : ['id' => $queuePost->id, 'name' => $queuePost->name],
            'tickets' => $queuePost === null
                ? []
                : array_values(ServiceQueueTicketResource::collection(
                    $queueService->ticketsForWaitingArea($event, $queuePost),
                )->resolve()),
            'positions' => array_values(OperationalParticipantResource::collection(
                $workflow->participantsWithActivePosition($event),
            )->resolve()),
        ];
    }

    private function statusFor(string $stage): ParticipantStatus
    {
        return match ($stage) {
            ParticipantStatus::Waiting->value => ParticipantStatus::Waiting,
            ParticipantStatus::Calling->value => ParticipantStatus::Calling,
            ParticipantStatus::HealthCheck->value => ParticipantStatus::HealthCheck,
            ParticipantStatus::Donating->value => ParticipantStatus::Donating,
            ParticipantStatus::Finished->value => ParticipantStatus::Finished,
            default => abort(404),
        };
    }

    private function actionResponse(
        OperationalWorkflowActionRequest $request,
        EventParticipant $participant,
        string $message,
    ): JsonResponse|RedirectResponse {
        if ($request->expectsJson()) {
            $participant->load(['event.settings', 'participant', 'services', 'queueTickets.servicePost']);

            return response()->json([
                'message' => $message,
                'data' => (new OperationalParticipantResource($participant))->resolve($request),
            ]);
        }

        return back()->with('status', $message);
    }
}
