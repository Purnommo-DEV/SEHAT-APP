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
use App\Services\Realtime\WorkflowRealtimePublisher;
use App\Services\Workflow\ParticipantStateMachine;
use App\Services\Workflow\QueueTicketStateMachine;
use App\Services\Workflow\WorkflowDefinitionService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Selected services remain independent metadata. Calling is the control-desk
 * state; health check, eligibility, donating, and finished are the visible
 * process stages.
 */
class OperationalWorkflowService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly AuditLogger $auditLogger,
        private readonly ParticipantStateMachine $stateMachine,
        private readonly QueueTicketStateMachine $ticketStateMachine,
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
                    ParticipantStatus::Calling,
                    ParticipantStatus::HealthCheck,
                    ParticipantStatus::WaitingScreening,
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

            if (! in_array($lockedParticipant->status, [ParticipantStatus::Waiting, ParticipantStatus::Calling], true)) {
                throw ValidationException::withMessages([
                    'participant' => "Status peserta sudah berubah menjadi {$lockedParticipant->status->label()}.",
                ]);
            }

            $healthService = $this->selectedService($lockedParticipant, ParticipantServiceType::HealthCheck);

            if (! ($healthService instanceof EventParticipantService)) {
                throw ValidationException::withMessages([
                    'participant' => 'Peserta yang hanya memilih Donor Darah harus langsung ke tahap Cek Kelayakan Donor.',
                ]);
            }

            $this->stateMachine->transition($lockedParticipant, ParticipantStatus::HealthCheck);
            $lockedParticipant->current_service_post_id = $healthPost->id;
            $lockedParticipant->save();

            $healthService->status = ParticipantServiceStatus::HealthCheckInProgress;
            $healthService->started_at ??= now();
            $healthService->save();

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

            $donorService = $this->requiredSelectedService($lockedParticipant, ParticipantServiceType::Donor);
            $this->ensureStatus($lockedParticipant, ParticipantStatus::WaitingScreening);

            $lockedParticipant->loadMissing('participant');
            $this->donationCapacity->reserveDonationSlot($activeEvent, $lockedParticipant->participant->gender);

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
            $donorService->eligibility_started_at ??= now();
            $donorService->eligibility_completed_at = now();
            $donorService->started_at ??= now();
            $donorService->save();
            $lockedParticipant->current_service_post_id = $donationPost->id;
            $lockedParticipant->save();

            $this->recordTransition($lockedParticipant, $oldStatus, $actor);
            $this->auditLogger->record(
                actor: $actor,
                subject: $lockedParticipant,
                action: AuditAction::ScreeningEligible,
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

        $this->realtimePublisher->operationalEligible($updated, $donorTicket);

        return $updated;
    }

    public function startEligibility(Event $event, EventParticipant $participant, ?User $actor): EventParticipant
    {
        /** @var EventParticipant $updated */
        $updated = $this->database->transaction(function () use ($event, $participant, $actor): EventParticipant {
            $lockedEvent = $this->lockActiveEvent($event);
            $lockedParticipant = $this->lockParticipant($lockedEvent, $participant);
            $screeningPost = $this->requiredPost($lockedEvent, ServicePostBehavior::ScreeningForm);
            $oldStatus = $lockedParticipant->status;

            $donorService = $this->requiredSelectedService($lockedParticipant, ParticipantServiceType::Donor);
            $healthService = $this->selectedService($lockedParticipant, ParticipantServiceType::HealthCheck);
            $fromHealthCheck = $lockedParticipant->status === ParticipantStatus::HealthCheck;
            $fromCalledDonorOnly = in_array($lockedParticipant->status, [
                ParticipantStatus::Waiting,
                ParticipantStatus::Calling,
            ], true) && ! ($healthService instanceof EventParticipantService);

            if (! $fromHealthCheck && ! $fromCalledDonorOnly) {
                throw ValidationException::withMessages([
                    'participant' => $healthService instanceof EventParticipantService
                        ? 'Peserta yang memilih Pemeriksaan Kesehatan harus menyelesaikan Cek Kesehatan terlebih dahulu.'
                        : "Status peserta sudah berubah menjadi {$lockedParticipant->status->label()}.",
                ]);
            }

            if ($fromHealthCheck) {
                $this->completeHealthServiceIfSelected($lockedParticipant);
            } else {
                $this->closeSupersededTickets($lockedParticipant, $actor);
            }

            $this->stateMachine->transition($lockedParticipant, ParticipantStatus::WaitingScreening);
            $donorService->status = ParticipantServiceStatus::WaitingScreening;
            $donorService->eligibility_started_at = now();
            $donorService->save();
            $lockedParticipant->current_service_post_id = $screeningPost->id;
            $lockedParticipant->save();

            $this->recordTransition($lockedParticipant, $oldStatus, $actor);
            $this->auditLogger->record(
                actor: $actor,
                subject: $lockedParticipant,
                action: AuditAction::ParticipantEligibilityStarted,
                eventId: $lockedEvent->id,
                oldValues: ['status' => $oldStatus->value],
                newValues: $this->participantSnapshot($lockedParticipant),
            );

            return $lockedParticipant;
        });

        $this->realtimePublisher->operationalMovedToEligibility($updated, $updated->current_service_post_id);

        return $updated;
    }

    public function markIneligible(Event $event, EventParticipant $participant, ?User $actor): EventParticipant
    {
        /** @var EventParticipant $updated */
        $updated = $this->database->transaction(function () use ($event, $participant, $actor): EventParticipant {
            $lockedEvent = $this->lockActiveEvent($event);
            $lockedParticipant = $this->lockParticipant($lockedEvent, $participant);
            $oldStatus = $lockedParticipant->status;

            $this->ensureStatus($lockedParticipant, ParticipantStatus::WaitingScreening);
            $donorService = $this->requiredSelectedService($lockedParticipant, ParticipantServiceType::Donor);
            $donorService->eligibility_started_at ??= now();
            $donorService->eligibility_completed_at = now();
            $donorService->completed_at = now();
            $donorService->status = ParticipantServiceStatus::NotEligible;
            $donorService->save();
            $this->completeHealthServiceIfSelected($lockedParticipant);
            $this->stateMachine->transition($lockedParticipant, ParticipantStatus::Finished);
            $lockedParticipant->current_service_post_id = null;
            $lockedParticipant->completed_at = now();
            $lockedParticipant->save();

            $this->recordTransition($lockedParticipant, $oldStatus, $actor);
            $this->auditLogger->record(
                actor: $actor,
                subject: $lockedParticipant,
                action: AuditAction::ScreeningNotEligible,
                eventId: $lockedEvent->id,
                oldValues: ['status' => $oldStatus->value],
                newValues: $this->participantSnapshot($lockedParticipant),
            );

            return $lockedParticipant;
        });

        $this->realtimePublisher->operationalIneligible($updated);

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
            if ($this->selectedService($lockedParticipant, ParticipantServiceType::Donor) instanceof EventParticipantService) {
                throw ValidationException::withMessages([
                    'participant' => 'Peserta donor harus melalui tahap Cek Kelayakan Donor.',
                ]);
            }
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
            ->select([
                'id',
                'event_id',
                'participant_id',
                'registration_number',
                'current_service_post_id',
                'status',
                'checked_in_at',
                'registration_order',
                'completed_at',
            ])
            ->where('event_id', $event->id)
            ->with([
                'event:id',
                'event.settings:id,event_id,registration_number_format,registration_queue_prefix,registration_male_prefix,registration_female_prefix,registration_queue_digits',
                'participant:id,name,gender',
                'services:id,event_participant_id,service,status',
            ])
            ->orderBy('registration_order')
            ->orderBy('checked_in_at')
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
        if ($participant->registration_number === null) {
            throw new \LogicException('Peserta yang belum memiliki nomor registrasi tidak dapat masuk proses donor.');
        }

        return QueueTicket::query()->create([
            'event_id' => $event->id,
            'event_participant_id' => $participant->id,
            'service_post_id' => $donationPost->id,
            // The donor ticket owns lifecycle data only. Its number is always
            // the registration number, and its gender lane keeps same-number
            // male/female registrations collision-safe at the database level.
            'queue_type' => QueueTicket::donorQueueTypeFor($participant->participant->gender),
            'number' => $participant->registration_number,
            'status' => QueueTicketStatus::Serving,
            'called_at' => now(),
            'called_by' => $actor?->id,
            'served_at' => now(),
        ]);
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
            'registration_order' => $participant->registration_order,
            'current_service_post_id' => $participant->current_service_post_id,
            'completed_at' => $participant->completed_at?->toIso8601String(),
        ];
    }
}
