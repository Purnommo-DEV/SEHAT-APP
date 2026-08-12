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
        return redirect()->route('events.operations.waiting', $event);
    }

    public function waiting(
        Event $event,
        OperationalWorkflowService $workflow,
        DonationCapacityService $donationCapacity,
    ): View {
        return $this->stageView(
            event: $event,
            workflow: $workflow,
            status: ParticipantStatus::Waiting,
            title: 'Area Tunggu',
            subtitle: 'Peserta yang belum masuk ke tahap Cek Kesehatan.',
            description: 'Pilih CEK KESEHATAN saat peserta mulai diproses. Nomor antrean terkecil selalu berada di atas.',
            action: 'start-health-check',
            queueDeskUrl: route('events.operations.waiting.desk', $event),
            donationCapacity: $donationCapacity->snapshot($event)->toArray(),
        );
    }

    public function waitingDesk(
        Event $event,
        ServiceQueueService $queueService,
        OperationalWorkflowService $workflow,
    ): View {
        $event->load('settings');

        return view('operations.waiting-desk', [
            'event' => $event,
            'queueJson' => $this->waitingPayload($event, $queueService, $workflow),
            'dataUrl' => route('events.operations.waiting.snapshot', $event),
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
    ): JsonResponse {
        return response()->json($this->waitingPayload($event, $queueService, $workflow));
    }

    public function healthCheck(
        Event $event,
        OperationalWorkflowService $workflow,
        DonationCapacityService $donationCapacity,
    ): View {
        return $this->stageView(
            event: $event,
            workflow: $workflow,
            status: ParticipantStatus::HealthCheck,
            title: 'Area Cek Kesehatan',
            subtitle: 'Peserta yang sedang berada pada tahap Cek Kesehatan.',
            description: 'Pilih DONOR untuk meneruskan proses donor, atau SELESAI bila peserta tidak melanjutkan proses donor.',
            action: 'before-donation',
            donationCapacity: $donationCapacity->snapshot($event)->toArray(),
        );
    }

    public function beforeDonation(Event $event): RedirectResponse
    {
        return redirect()->route('events.operations.health-check', $event);
    }

    public function donating(
        Event $event,
        OperationalWorkflowService $workflow,
        DonationCapacityService $donationCapacity,
    ): View {
        return $this->stageView(
            event: $event,
            workflow: $workflow,
            status: ParticipantStatus::Donating,
            title: 'Area Sedang Donor',
            subtitle: 'Peserta yang sedang menjalani donor.',
            description: 'Selesaikan peserta setelah proses donor di area ini selesai.',
            action: 'complete-donation',
            donationCapacity: $donationCapacity->snapshot($event)->toArray(),
        );
    }

    public function completed(
        Event $event,
        OperationalWorkflowService $workflow,
        DonationCapacityService $donationCapacity,
    ): View {
        return $this->stageView(
            event: $event,
            workflow: $workflow,
            status: ParticipantStatus::Finished,
            title: 'Area Selesai',
            subtitle: 'Riwayat peserta yang telah menyelesaikan alur operasional.',
            description: 'Daftar ini bersifat read-only.',
            action: 'read-only',
            donationCapacity: $donationCapacity->snapshot($event)->toArray(),
        );
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
     *     queue: array{post: array{id: int, name: string}|null, tickets: list<mixed>, positions: list<mixed>}
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

    /**
     * @param  array<string, mixed>|null  $donationCapacity
     */
    private function stageView(
        Event $event,
        OperationalWorkflowService $workflow,
        ParticipantStatus $status,
        string $title,
        string $subtitle,
        string $description,
        string $action,
        ?string $queueDeskUrl = null,
        ?array $donationCapacity = null,
    ): View {
        $event->load('settings');

        return view('operations.stage', [
            'event' => $event,
            'title' => $title,
            'subtitle' => $subtitle,
            'description' => $description,
            'action' => $action,
            'participantsJson' => array_values(
                OperationalParticipantResource::collection(
                    $workflow->participantsForStage($event, $status),
                )->resolve(),
            ),
            'dataUrl' => route('events.operations.data', [$event, $status->value]),
            'queueDeskUrl' => $queueDeskUrl,
            'donationCapacity' => $donationCapacity,
            'canUpdateDonationCapacity' => auth()->user()?->can('updateDonationCapacity', $event) ?? false,
            'capacityUpdateUrl' => route('events.operations.donation-capacity.update', $event),
        ]);
    }

    private function statusFor(string $stage): ParticipantStatus
    {
        return match ($stage) {
            ParticipantStatus::Waiting->value => ParticipantStatus::Waiting,
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
