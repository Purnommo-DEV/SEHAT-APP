<?php

namespace App\Services\Operational;

use App\Enums\AuditAction;
use App\Enums\EventStatus;
use App\Enums\ParticipantServiceStatus;
use App\Enums\ParticipantServiceType;
use App\Enums\ParticipantStatus;
use App\Enums\QueueTicketStatus;
use App\Enums\ServicePostBehavior;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventParticipantService;
use App\Models\EventParticipantStatusHistory;
use App\Models\QueueTicket;
use App\Models\ServicePost;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Queue\QueueNumberGenerator;
use App\Services\Realtime\WorkflowRealtimePublisher;
use App\Services\Workflow\ParticipantStateMachine;
use App\Services\Workflow\QueueTicketStateMachine;
use App\Services\Workflow\WorkflowDefinitionService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * The operational donor lane intentionally has just four visible states:
 * waiting, health check, donating, and finished. Selected services remain
 * independent metadata for reporting and the health-only completion branch.
 */
class OperationalWorkflowService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly AuditLogger $auditLogger,
        private readonly ParticipantStateMachine $stateMachine,
        private readonly QueueTicketStateMachine $ticketStateMachine,
        private readonly QueueNumberGenerator $queueNumberGenerator,
        private readonly DonationCapacityService $donationCapacity,
        private readonly WorkflowDefinitionService $workflow,
        private readonly WorkflowRealtimePublisher $realtimePublisher,
    ) {}

    /**
     * @return Collection<int, EventParticipant>
     */
    public function participantsForStage(Event $event, ParticipantStatus $status): Collection
    {
        if (! $status->isOperationalStage()) {
            throw ValidationException::withMessages([
                'status' => 'Tahap operasional tidak valid.',
            ]);
        }

        return $this->operationalParticipantsQuery($event)
            ->where('status', $status->value)
            ->get();
    }

    /**
     * @return array<string, Collection<int, EventParticipant>>
     */
    public function participantsForOperationalScreen(Event $event): array
    {
        $participants = $this->operationalParticipantsQuery($event)
            ->whereIn('status', array_map(
                fn (ParticipantStatus $status): string => $status->value,
                ParticipantStatus::operationalStages(),
            ))
            ->get();

        return collect(ParticipantStatus::operationalStages())
            ->mapWithKeys(fn (ParticipantStatus $status): array => [
                $status->value => $participants
                    ->where('status', $status)
                    ->values(),
            ])
            ->all();
    }

    /**
     * Returns every participant that is still progressing through the
     * operational workflow. Screen Petugas uses this alongside its waiting
     * tickets so moving a participant closes only their previous queue entry,
     * never their visible current position.
     *
     * @return Collection<int, EventParticipant>
     */
    public function participantsWithActivePosition(Event $event): Collection
    {
        return $this->operationalParticipantsQuery($event)
            ->whereIn('status', array_map(
                fn (ParticipantStatus $status): string => $status->value,
                [
                    ParticipantStatus::Waiting,
                    ParticipantStatus::HealthCheck,
                    ParticipantStatus::Donating,
                ],
            ))
            ->get();
    }

    public function startHealthCheck(Event $event, EventParticipant $participant, ?User $actor): EventParticipant
    {
        /** @var EventParticipant $updated */
        $updated = $this->database->transaction(function () use ($event, $participant, $actor): EventParticipant {
            $lockedEvent = $this->lockActiveEvent($event);
            $lockedParticipant = $this->lockParticipant($lockedEvent, $participant);
            $healthPost = $this->requiredPost($lockedEvent, ServicePostBehavior::HealthForm);
            $oldStatus = $lockedParticipant->status;

            $this->ensureStatus($lockedParticipant, ParticipantStatus::Waiting);
            $this->stateMachine->transition($lockedParticipant, ParticipantStatus::HealthCheck);
            $lockedParticipant->current_service_post_id = $healthPost->id;
            $lockedParticipant->save();

            $healthService = $this->selectedService($lockedParticipant, ParticipantServiceType::HealthCheck);
            if ($healthService instanceof EventParticipantService) {
                $healthService->status = ParticipantServiceStatus::HealthCheckInProgress;
                $healthService->started_at ??= now();
                $healthService->save();
            }

            $this->closeSupersededTickets($lockedParticipant, $actor);
            $this->recordTransition($lockedParticipant, $oldStatus, $actor);
            $this->auditLogger->record(
                actor: $actor,
                subject: $lockedParticipant,
                action: AuditAction::ParticipantHealthCheckStarted,
                eventId: $lockedEvent->id,
                oldValues: ['status' => $oldStatus->value],
                newValues: $this->participantSnapshot($lockedParticipant),
            );

            return $lockedParticipant;
        });

        $this->realtimePublisher->operationalHealthCheckStarted($updated, $updated->current_service_post_id);

        return $updated;
    }

    public function startDonation(Event $event, EventParticipant $participant, ?User $actor): EventParticipant
    {
        $donorTicket = null;
        /** @var EventParticipant $updated */
        $updated = $this->database->transaction(function () use ($event, $participant, $actor, &$donorTicket): EventParticipant {
            $activeEvent = $this->activeEvent($event);
            $lockedParticipant = $this->lockParticipant($activeEvent, $participant);
            $donationPost = $this->requiredPost($activeEvent, ServicePostBehavior::DonationForm);
            $oldStatus = $lockedParticipant->status;

            $this->ensureStatus($lockedParticipant, ParticipantStatus::HealthCheck);
            $donorService = $this->requiredSelectedService($lockedParticipant, ParticipantServiceType::Donor);
            $lockedParticipant->loadMissing('participant');
            $this->donationCapacity->reserveDonationSlot($activeEvent, $lockedParticipant->participant->gender);
            $this->completeHealthServiceIfSelected($lockedParticipant);

            $donorTicket = $this->issueDonorTicket(
                $activeEvent,
                $lockedParticipant,
                $donationPost,
                $actor,
            );
            // Keep audit formatting inside the current transaction query-free.
            $donorTicket->setRelation('event', $activeEvent->loadMissing('settings'));
            $donorTicket->setRelation('servicePost', $donationPost);

            $this->stateMachine->transition($lockedParticipant, ParticipantStatus::Donating);
            $donorService->status = ParticipantServiceStatus::DonationInProgress;
            $donorService->started_at ??= now();
            $donorService->save();
            $lockedParticipant->current_service_post_id = $donationPost->id;
            $lockedParticipant->save();

            $this->recordTransition($lockedParticipant, $oldStatus, $actor);
            $this->auditLogger->record(
                actor: $actor,
                subject: $lockedParticipant,
                action: AuditAction::ParticipantMovedToDonation,
                eventId: $activeEvent->id,
                oldValues: ['status' => $oldStatus->value],
                newValues: [
                    ...$this->participantSnapshot($lockedParticipant),
                    'donor_ticket_id' => $donorTicket->id,
                    'donor_number' => $donorTicket->formattedNumber(),
                ],
            );

            return $lockedParticipant;
        });

        if (! $donorTicket instanceof QueueTicket) {
            throw new \LogicException('Nomor donor tidak berhasil dibuat.');
        }

        $this->realtimePublisher->operationalDonationStarted($updated, $donorTicket);

        return $updated;
    }

    public function complete(Event $event, EventParticipant $participant, ?User $actor): EventParticipant
    {
        $donorTicket = null;
        /** @var EventParticipant $updated */
        $updated = $this->database->transaction(function () use ($event, $participant, $actor, &$donorTicket): EventParticipant {
            $activeEvent = $this->activeEvent($event);
            $lockedParticipant = $this->lockParticipant($activeEvent, $participant);
            $oldStatus = $lockedParticipant->status;

            $this->ensureStatus($lockedParticipant, ParticipantStatus::Donating);
            $this->donationCapacity->lockGenderLane($activeEvent, $lockedParticipant->participant->gender);
            $donorService = $this->requiredSelectedService($lockedParticipant, ParticipantServiceType::Donor);
            $donorTicket = QueueTicket::query()
                ->where('event_id', $activeEvent->id)
                ->where('event_participant_id', $lockedParticipant->id)
                ->whereHas('servicePost', fn ($query) => $query->where('behavior', ServicePostBehavior::DonationForm->value))
                ->latest('id')
                ->lockForUpdate()
                ->firstOrFail();

            if ($donorTicket->status !== QueueTicketStatus::Serving) {
                throw ValidationException::withMessages([
                    'participant' => 'Nomor donor peserta ini tidak lagi berada pada proses donor aktif.',
                ]);
            }

            $this->ticketStateMachine->transition($donorTicket, QueueTicketStatus::Finished);
            $donorTicket->finished_at = now();
            $donorTicket->save();
            $this->stateMachine->transition($lockedParticipant, ParticipantStatus::Finished);
            $donorService->status = ParticipantServiceStatus::Completed;
            $donorService->completed_at = now();
            $donorService->save();
            $this->completeHealthServiceIfSelected($lockedParticipant);
            $lockedParticipant->current_service_post_id = null;
            $lockedParticipant->completed_at = now();
            $lockedParticipant->save();

            $this->recordTransition($lockedParticipant, $oldStatus, $actor);
            $this->auditLogger->record(
                actor: $actor,
                subject: $lockedParticipant,
                action: AuditAction::ParticipantWorkflowCompleted,
                eventId: $activeEvent->id,
                oldValues: ['status' => $oldStatus->value],
                newValues: $this->participantSnapshot($lockedParticipant),
            );

            return $lockedParticipant;
        });

        $this->realtimePublisher->operationalCompleted($updated, $donorTicket);

        return $updated;
    }

    public function completeBeforeDonation(Event $event, EventParticipant $participant, ?User $actor): EventParticipant
    {
        /** @var EventParticipant $updated */
        $updated = $this->database->transaction(function () use ($event, $participant, $actor): EventParticipant {
            $lockedEvent = $this->lockActiveEvent($event);
            $lockedParticipant = $this->lockParticipant($lockedEvent, $participant);
            $oldStatus = $lockedParticipant->status;

            $this->ensureStatus($lockedParticipant, ParticipantStatus::HealthCheck);
            $this->cancelDonorServiceIfSelected($lockedParticipant);
            $this->completeHealthServiceIfSelected($lockedParticipant);
            $this->stateMachine->transition($lockedParticipant, ParticipantStatus::Finished);
            $lockedParticipant->current_service_post_id = null;
            $lockedParticipant->completed_at = now();
            $lockedParticipant->save();

            $this->recordTransition($lockedParticipant, $oldStatus, $actor);
            $this->auditLogger->record(
                actor: $actor,
                subject: $lockedParticipant,
                action: AuditAction::ParticipantWorkflowCompleted,
                eventId: $lockedEvent->id,
                oldValues: ['status' => $oldStatus->value],
                newValues: $this->participantSnapshot($lockedParticipant),
            );

            return $lockedParticipant;
        });

        $this->realtimePublisher->operationalCompleted($updated);

        return $updated;
    }

    private function lockActiveEvent(Event $event): Event
    {
        $lockedEvent = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

        if ($lockedEvent->status !== EventStatus::Active || $lockedEvent->active_marker !== 'active') {
            throw ValidationException::withMessages([
                'event' => 'Workflow operasional hanya dapat diproses pada event aktif.',
            ]);
        }

        return $lockedEvent;
    }

    private function activeEvent(Event $event): Event
    {
        // A shared event lock lets the male and female donor lanes continue
        // independently, while an administrative reset obtains the exclusive
        // counterpart before deleting any operational records.
        $activeEvent = Event::query()->whereKey($event->id)->sharedLock()->firstOrFail();

        if ($activeEvent->status !== EventStatus::Active || $activeEvent->active_marker !== 'active') {
            throw ValidationException::withMessages([
                'event' => 'Workflow operasional hanya dapat diproses pada event aktif.',
            ]);
        }

        return $activeEvent;
    }

    /**
     * @return Builder<EventParticipant>
     */
    private function operationalParticipantsQuery(Event $event): Builder
    {
        return EventParticipant::query()
            ->where('event_id', $event->id)
            ->with([
                'event.settings',
                'participant',
                'services',
                'queueTickets.servicePost',
            ])
            ->orderBy('registration_number')
            ->orderBy('id');
    }

    private function lockParticipant(Event $event, EventParticipant $participant): EventParticipant
    {
        return EventParticipant::query()
            ->where('event_id', $event->id)
            ->whereKey($participant->id)
            ->with('participant')
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function requiredPost(Event $event, ServicePostBehavior $behavior): ServicePost
    {
        $post = $this->workflow->firstActivePostForBehavior($event, $behavior, true);

        if (! $post instanceof ServicePost) {
            throw ValidationException::withMessages([
                'event' => "Pos {$behavior->label()} yang aktif harus tersedia.",
            ]);
        }

        return $post;
    }

    private function ensureStatus(EventParticipant $participant, ParticipantStatus $expected): void
    {
        if ($participant->status !== $expected) {
            throw ValidationException::withMessages([
                'participant' => "Status peserta sudah berubah menjadi {$participant->status->label()}.",
            ]);
        }
    }

    private function selectedService(EventParticipant $participant, ParticipantServiceType $type): ?EventParticipantService
    {
        $service = EventParticipantService::query()
            ->where('event_participant_id', $participant->id)
            ->where('service', $type->value)
            ->lockForUpdate()
            ->first();

        return $service instanceof EventParticipantService ? $service : null;
    }

    private function requiredSelectedService(EventParticipant $participant, ParticipantServiceType $type): EventParticipantService
    {
        $service = $this->selectedService($participant, $type);

        if (! $service instanceof EventParticipantService) {
            throw ValidationException::withMessages([
                'participant' => "Peserta tidak memilih layanan {$type->label()}.",
            ]);
        }

        return $service;
    }

    private function completeHealthServiceIfSelected(EventParticipant $participant): void
    {
        $service = $this->selectedService($participant, ParticipantServiceType::HealthCheck);

        if (! $service instanceof EventParticipantService || $service->status === ParticipantServiceStatus::Completed) {
            return;
        }

        $service->started_at ??= now();
        $service->status = ParticipantServiceStatus::Completed;
        $service->completed_at = now();
        $service->save();
    }

    private function cancelDonorServiceIfSelected(EventParticipant $participant): void
    {
        $service = $this->selectedService($participant, ParticipantServiceType::Donor);

        if (! $service instanceof EventParticipantService || $service->status->isTerminal()) {
            return;
        }

        $service->status = ParticipantServiceStatus::Cancelled;
        $service->completed_at = now();
        $service->save();
    }

    private function closeSupersededTickets(EventParticipant $participant, ?User $actor): void
    {
        $tickets = QueueTicket::query()
            ->where('event_participant_id', $participant->id)
            ->whereIn('status', [
                QueueTicketStatus::Waiting->value,
                QueueTicketStatus::Calling->value,
                QueueTicketStatus::Skipped->value,
            ])
            ->lockForUpdate()
            ->get();

        foreach ($tickets as $ticket) {
            $this->ticketStateMachine->transition($ticket, QueueTicketStatus::Cancelled);
            $ticket->active_number = null;
            $ticket->cancelled_at = now();
            $ticket->cancelled_by = $actor?->id;
            $ticket->save();
        }
    }

    private function issueDonorTicket(
        Event $event,
        EventParticipant $participant,
        ServicePost $donationPost,
        ?User $actor,
    ): QueueTicket {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                /** @var QueueTicket $ticket */
                $ticket = $this->database->transaction(function () use ($event, $participant, $donationPost, $actor): QueueTicket {
                    $donorNumber = $this->queueNumberGenerator->nextDonor(
                        $event,
                        $participant->participant->gender,
                    );

                    return QueueTicket::query()->create([
                        'event_id' => $event->id,
                        'event_participant_id' => $participant->id,
                        'service_post_id' => $donationPost->id,
                        'queue_type' => $donorNumber->queueType,
                        'number' => $donorNumber->number,
                        'status' => QueueTicketStatus::Serving,
                        'called_at' => now(),
                        'called_by' => $actor?->id,
                        'served_at' => now(),
                    ]);
                });

                return $ticket;
            } catch (QueryException $exception) {
                if (! $this->isActiveQueueNumberCollision($exception) || $attempt === 4) {
                    throw $exception;
                }
            }
        }

        throw new \LogicException('Nomor donor tidak berhasil dibuat.');
    }

    private function isActiveQueueNumberCollision(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true)
            && (str_contains($exception->getMessage(), 'active_number')
                || str_contains($exception->getMessage(), 'event_scope_active_number'));
    }

    private function recordTransition(EventParticipant $participant, ParticipantStatus $from, ?User $actor): void
    {
        EventParticipantStatusHistory::query()->create([
            'event_id' => $participant->event_id,
            'event_participant_id' => $participant->id,
            'from_status' => $from,
            'to_status' => $participant->status,
            'changed_by' => $actor?->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function participantSnapshot(EventParticipant $participant): array
    {
        return [
            'status' => $participant->status->value,
            'current_service_post_id' => $participant->current_service_post_id,
            'completed_at' => $participant->completed_at?->toIso8601String(),
        ];
    }
}
