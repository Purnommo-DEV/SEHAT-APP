<?php

namespace App\Services\Health;

use App\Data\HealthAssessmentCompletionResult;
use App\Enums\AuditAction;
use App\Enums\EventStatus;
use App\Enums\ParticipantStatus;
use App\Enums\QueueTicketStatus;
use App\Enums\ServicePostBehavior;
use App\Events\HealthQueueUpdated;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\HealthAssessment;
use App\Models\QueueTicket;
use App\Models\ServicePost;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Realtime\WorkflowRealtimePublisher;
use App\Services\Workflow\LegacyServicePostBehaviorResolver;
use App\Services\Workflow\ParticipantStateMachine;
use App\Services\Workflow\QueueTicketStateMachine;
use App\Services\Workflow\ServiceQueueService;
use App\Services\Workflow\WorkflowDefinitionService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class HealthQueueService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly AuditLogger $auditLogger,
        private readonly QueueTicketStateMachine $queueStateMachine,
        private readonly ParticipantStateMachine $participantStateMachine,
        private readonly WorkflowDefinitionService $workflow,
        private readonly LegacyServicePostBehaviorResolver $legacyPostBehavior,
        private readonly ServiceQueueService $serviceQueueService,
        private readonly WorkflowRealtimePublisher $realtimePublisher,
    ) {}

    /**
     * @return Collection<int, QueueTicket>
     */
    public function ticketsForEvent(Event $event): Collection
    {
        $relations = [
            'event.settings',
            'eventParticipant.participant',
            'eventParticipant.healthAssessment',
            'servicePost',
            'calledBy',
        ];
        $activeTickets = QueueTicket::query()
            ->where('event_id', $event->id)
            ->whereHas('servicePost', fn ($query) => $this->legacyPostBehavior->apply($query, ServicePostBehavior::HealthForm))
            ->where('status', '!=', QueueTicketStatus::Finished->value)
            ->with($relations)
            ->orderBy('number')
            ->get();
        $finishedTickets = QueueTicket::query()
            ->where('event_id', $event->id)
            ->whereHas('servicePost', fn ($query) => $this->legacyPostBehavior->apply($query, ServicePostBehavior::HealthForm))
            ->where('status', QueueTicketStatus::Finished->value)
            ->with($relations)
            ->latest('finished_at')
            ->limit(20)
            ->get();

        return $activeTickets
            ->concat($finishedTickets)
            ->unique('id')
            ->values();
    }

    public function call(Event $event, QueueTicket $queueTicket, User $actor): QueueTicket
    {
        if ($this->usesCanonicalWorkflow($queueTicket)) {
            $updatedTicket = $this->serviceQueueService->call(
                $event,
                $this->canonicalHealthPost($event, $queueTicket),
                $queueTicket,
                $actor,
            );
            $this->broadcastLegacy($updatedTicket, AuditAction::QueueTicketCalled);

            return $updatedTicket;
        }

        $updatedTicket = $this->database->transaction(function () use ($event, $queueTicket, $actor): QueueTicket {
            $lockedEvent = $this->lockActiveEvent($event);
            $lockedTicket = $this->lockHealthTicket($lockedEvent, $queueTicket, true);
            $this->lockEventParticipant($lockedTicket);
            $oldValues = $this->ticketSnapshot($lockedTicket);

            $this->queueStateMachine->transition($lockedTicket, QueueTicketStatus::Calling);
            $lockedTicket->called_at = now();
            $lockedTicket->called_by = $actor->id;
            $lockedTicket->save();

            $this->writeTicketAudit($lockedTicket, $actor, AuditAction::QueueTicketCalled, $oldValues);

            return $lockedTicket;
        });

        $this->broadcast($updatedTicket, AuditAction::QueueTicketCalled);

        return $updatedTicket;
    }

    public function start(Event $event, QueueTicket $queueTicket, User $actor): QueueTicket
    {
        if ($this->usesCanonicalWorkflow($queueTicket)) {
            $updatedTicket = $this->serviceQueueService->start(
                $event,
                $this->canonicalHealthPost($event, $queueTicket),
                $queueTicket,
                $actor,
            );
            $this->broadcastLegacy($updatedTicket, AuditAction::QueueTicketServing);

            return $updatedTicket;
        }

        $updatedTicket = $this->database->transaction(function () use ($event, $queueTicket, $actor): QueueTicket {
            $lockedEvent = $this->lockActiveEvent($event);
            $lockedTicket = $this->lockHealthTicket($lockedEvent, $queueTicket, true);
            $eventParticipant = $this->lockEventParticipant($lockedTicket);
            $oldValues = $this->ticketSnapshot($lockedTicket);

            $this->queueStateMachine->transition($lockedTicket, QueueTicketStatus::Serving);
            $this->participantStateMachine->transition($eventParticipant, ParticipantStatus::HealthInProgress);
            $lockedTicket->served_at = now();
            $lockedTicket->save();
            $eventParticipant->save();

            $this->writeTicketAudit($lockedTicket, $actor, AuditAction::QueueTicketServing, $oldValues);

            return $lockedTicket;
        });

        $this->broadcast($updatedTicket, AuditAction::QueueTicketServing);

        return $updatedTicket;
    }

    public function skip(Event $event, QueueTicket $queueTicket, User $actor): QueueTicket
    {
        if ($this->usesCanonicalWorkflow($queueTicket)) {
            $updatedTicket = $this->serviceQueueService->skip(
                $event,
                $this->canonicalHealthPost($event, $queueTicket),
                $queueTicket,
                $actor,
            );
            $this->broadcastLegacy($updatedTicket, AuditAction::QueueTicketSkipped);

            return $updatedTicket;
        }

        $updatedTicket = $this->database->transaction(function () use ($event, $queueTicket, $actor): QueueTicket {
            $lockedEvent = $this->lockActiveEvent($event);
            $lockedTicket = $this->lockHealthTicket($lockedEvent, $queueTicket, true);
            $this->lockEventParticipant($lockedTicket);
            $oldValues = $this->ticketSnapshot($lockedTicket);

            $this->queueStateMachine->transition($lockedTicket, QueueTicketStatus::Skipped);
            $lockedTicket->save();

            $this->writeTicketAudit($lockedTicket, $actor, AuditAction::QueueTicketSkipped, $oldValues);

            return $lockedTicket;
        });

        $this->broadcast($updatedTicket, AuditAction::QueueTicketSkipped);

        return $updatedTicket;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function complete(
        Event $event,
        QueueTicket $queueTicket,
        array $attributes,
        User $actor,
    ): HealthAssessmentCompletionResult {
        if ($this->usesCanonicalWorkflow($queueTicket)) {
            return $this->completeCanonical($event, $queueTicket, $attributes, $actor);
        }

        $eventParticipantId = null;
        $nextPost = null;
        /** @var HealthAssessmentCompletionResult $result */
        $result = $this->database->transaction(function () use (
            $event,
            $queueTicket,
            $attributes,
            $actor,
            &$eventParticipantId,
            &$nextPost,
        ): HealthAssessmentCompletionResult {
            $lockedEvent = $this->lockActiveEvent($event);
            $lockedTicket = $this->lockHealthTicket($lockedEvent, $queueTicket);
            $eventParticipant = $this->lockEventParticipant($lockedTicket);

            if ($lockedTicket->status === QueueTicketStatus::Finished) {
                $assessment = HealthAssessment::query()
                    ->where('event_participant_id', $eventParticipant->id)
                    ->where(function ($query) use ($lockedTicket): void {
                        $query->where('service_post_id', $lockedTicket->service_post_id)
                            ->orWhereNull('service_post_id');
                    })
                    ->firstOrFail();

                return new HealthAssessmentCompletionResult($assessment, true);
            }

            $eventParticipantId = $eventParticipant->id;
            $oldTicketValues = $this->ticketSnapshot($lockedTicket);
            $oldParticipantStatus = $eventParticipant->status;
            $this->queueStateMachine->transition($lockedTicket, QueueTicketStatus::Finished);
            $this->participantStateMachine->transition($eventParticipant, ParticipantStatus::WaitingScreening);
            $nextPost = $this->workflow->nextActivePost($lockedEvent, $lockedTicket->servicePost, true);

            if ($nextPost === null) {
                throw ValidationException::withMessages([
                    'event' => 'Pos pelayanan aktif berikutnya harus tersedia sebelum pemeriksaan dapat diselesaikan melalui rute kompatibilitas ini.',
                ]);
            }

            $assessment = HealthAssessment::query()->create([
                ...$attributes,
                'event_id' => $lockedEvent->id,
                'event_participant_id' => $eventParticipant->id,
                'service_post_id' => $lockedTicket->service_post_id,
                'created_by' => $actor->id,
            ]);
            $lockedTicket->finished_at = now();
            $lockedTicket->save();
            $eventParticipant->current_service_post_id = $nextPost->id;
            $eventParticipant->save();

            $this->writeTicketAudit($lockedTicket, $actor, AuditAction::HealthAssessmentCompleted, $oldTicketValues);
            $this->auditLogger->record(
                actor: $actor,
                subject: $assessment,
                action: AuditAction::HealthAssessmentCompleted,
                eventId: $lockedEvent->id,
                oldValues: ['participant_status' => $oldParticipantStatus->value],
                newValues: [
                    ...$this->assessmentSnapshot($assessment),
                    'participant_status' => $eventParticipant->status->value,
                    'current_service_post_id' => $nextPost->id,
                ],
            );

            return new HealthAssessmentCompletionResult($assessment, false);
        });

        if (! $result->alreadyCompleted) {
            if ($eventParticipantId === null) {
                throw new \LogicException('Participant context was not captured for health completion.');
            }

            $this->realtimePublisher->serviceCompleted(
                $queueTicket,
                $eventParticipantId,
                ServicePostBehavior::HealthForm,
                null,
                $nextPost === null ? null : $this->legacyPostBehavior->resolve($nextPost),
                $nextPost?->id,
                null,
            );
            if ($nextPost !== null
                && $this->legacyPostBehavior->matches($nextPost, ServicePostBehavior::ScreeningForm)
            ) {
                $this->realtimePublisher->movedToEligibility(
                    $queueTicket,
                    $eventParticipantId,
                    $nextPost->id,
                    null,
                );
            }
            $this->broadcast($queueTicket, AuditAction::HealthAssessmentCompleted);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(
        Event $event,
        HealthAssessment $healthAssessment,
        array $attributes,
        User $actor,
    ): HealthAssessment {
        $updatedAssessment = $this->database->transaction(function () use ($event, $healthAssessment, $attributes, $actor): HealthAssessment {
            $this->lockActiveEvent($event);
            $lockedAssessment = HealthAssessment::query()
                ->where('event_id', $event->id)
                ->whereKey($healthAssessment->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $oldValues = $this->assessmentSnapshot($lockedAssessment);
            $lockedAssessment->fill($attributes);
            $lockedAssessment->save();

            $this->auditLogger->record(
                actor: $actor,
                subject: $lockedAssessment,
                action: AuditAction::HealthAssessmentUpdated,
                eventId: $event->id,
                oldValues: $oldValues,
                newValues: $this->assessmentSnapshot($lockedAssessment),
            );

            return $lockedAssessment;
        });

        $tickets = QueueTicket::query()
            ->where('event_id', $event->id)
            ->where('event_participant_id', $updatedAssessment->event_participant_id);
        $ticket = $updatedAssessment->service_post_id === null
            ? $tickets
                ->whereHas('servicePost', fn ($query) => $this->legacyPostBehavior->apply($query, ServicePostBehavior::HealthForm))
                ->firstOrFail()
            : $tickets->where('service_post_id', $updatedAssessment->service_post_id)->firstOrFail();
        $this->broadcast($ticket, AuditAction::HealthAssessmentUpdated);

        return $updatedAssessment;
    }

    private function lockActiveEvent(Event $event): Event
    {
        $lockedEvent = Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

        if ($lockedEvent->status !== EventStatus::Active || $lockedEvent->active_marker !== 'active') {
            throw ValidationException::withMessages([
                'event' => 'Antrean kesehatan hanya dapat dikelola pada event yang sedang aktif.',
            ]);
        }

        return $lockedEvent;
    }

    private function lockHealthTicket(Event $event, QueueTicket $queueTicket, bool $requireActivePost = false): QueueTicket
    {
        $lockedTicket = QueueTicket::query()
            ->where('event_id', $event->id)
            ->whereKey($queueTicket->getKey())
            ->lockForUpdate()
            ->firstOrFail();
        $post = ServicePost::query()->whereKey($lockedTicket->service_post_id)->lockForUpdate()->firstOrFail();

        if (! $this->legacyPostBehavior->matches($post, ServicePostBehavior::HealthForm)
            || ($requireActivePost && ! $post->is_active)) {
            throw ValidationException::withMessages([
                'queue_ticket' => 'Tiket tidak berada pada Pos Pemeriksaan Kesehatan yang aktif.',
            ]);
        }

        $lockedTicket->setRelation('servicePost', $post);

        return $lockedTicket;
    }

    private function lockEventParticipant(QueueTicket $queueTicket): EventParticipant
    {
        $eventParticipant = EventParticipant::query()
            ->whereKey($queueTicket->event_participant_id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($eventParticipant->services()->exists()) {
            throw ValidationException::withMessages([
                'queue_ticket' => 'Peserta SOP baru wajib diproses oleh antrean layanan kanonik.',
            ]);
        }

        return $eventParticipant;
    }

    /**
     * @return array<string, mixed>
     */
    private function ticketSnapshot(QueueTicket $queueTicket): array
    {
        return [
            'status' => $queueTicket->status->value,
            'called_at' => $queueTicket->called_at?->toIso8601String(),
            'served_at' => $queueTicket->served_at?->toIso8601String(),
            'finished_at' => $queueTicket->finished_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assessmentSnapshot(HealthAssessment $assessment): array
    {
        return [
            'blood_pressure' => $assessment->blood_pressure,
            'blood_sugar' => $assessment->blood_sugar,
            'cholesterol' => $assessment->cholesterol,
            'uric_acid' => $assessment->uric_acid,
            'notes' => $assessment->notes,
        ];
    }

    /**
     * @param  array<string, mixed>  $oldValues
     */
    private function writeTicketAudit(
        QueueTicket $queueTicket,
        User $actor,
        AuditAction $action,
        array $oldValues,
    ): void {
        $this->auditLogger->record(
            actor: $actor,
            subject: $queueTicket,
            action: $action,
            eventId: $queueTicket->event_id,
            oldValues: $oldValues,
            newValues: $this->ticketSnapshot($queueTicket),
        );
    }

    private function broadcast(QueueTicket $queueTicket, AuditAction $action): void
    {
        $this->realtimePublisher->queueChanged($queueTicket, $action);
        $this->broadcastLegacy($queueTicket, $action);
    }

    private function broadcastLegacy(QueueTicket $queueTicket, AuditAction $action): void
    {
        HealthQueueUpdated::dispatch($queueTicket->event_id, $queueTicket->id, $action);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function completeCanonical(
        Event $event,
        QueueTicket $queueTicket,
        array $attributes,
        User $actor,
    ): HealthAssessmentCompletionResult {
        $servicePost = $this->canonicalHealthPost($event, $queueTicket);
        $alreadyCompleted = QueueTicket::query()
            ->where('event_id', $event->id)
            ->whereKey($queueTicket->id)
            ->where('status', QueueTicketStatus::Finished->value)
            ->exists();

        if ($alreadyCompleted) {
            $assessment = HealthAssessment::query()
                ->where('event_id', $event->id)
                ->where('event_participant_id', $queueTicket->event_participant_id)
                ->where('service_post_id', $servicePost->id)
                ->latest('id')
                ->firstOrFail();

            return new HealthAssessmentCompletionResult($assessment, true);
        }

        $updatedTicket = $this->serviceQueueService->complete(
            $event,
            $servicePost,
            $queueTicket,
            $attributes,
            $actor,
        );
        $assessment = HealthAssessment::query()
            ->where('event_id', $event->id)
            ->where('event_participant_id', $updatedTicket->event_participant_id)
            ->where('service_post_id', $servicePost->id)
            ->latest('id')
            ->firstOrFail();

        $this->broadcastLegacy($updatedTicket, AuditAction::HealthAssessmentCompleted);

        return new HealthAssessmentCompletionResult($assessment, false);
    }

    private function usesCanonicalWorkflow(QueueTicket $queueTicket): bool
    {
        return EventParticipant::query()
            ->whereKey($queueTicket->event_participant_id)
            ->whereHas('services')
            ->exists();
    }

    private function canonicalHealthPost(Event $event, QueueTicket $queueTicket): ServicePost
    {
        $servicePost = ServicePost::query()
            ->where('event_id', $event->id)
            ->whereKey($queueTicket->service_post_id)
            ->firstOrFail();

        if ($servicePost->behavior !== ServicePostBehavior::HealthForm) {
            throw ValidationException::withMessages([
                'queue_ticket' => 'Peserta SOP baru wajib diproses melalui Pos Pemeriksaan Kesehatan kanonik.',
            ]);
        }

        return $servicePost;
    }
}
