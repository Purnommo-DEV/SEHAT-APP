<?php

namespace App\Services\Workflow;

use App\Enums\AuditAction;
use App\Enums\EventStatus;
use App\Enums\ParticipantStatus;
use App\Enums\QueueTicketStatus;
use App\Enums\QueueType;
use App\Enums\ScreeningResult;
use App\Enums\ServicePostBehavior;
use App\Events\ServiceQueueUpdated;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\QueueTicket;
use App\Models\ServicePost;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Queue\QueueNumberGenerator;
use App\Services\Realtime\WorkflowRealtimePublisher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class ServiceQueueService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly AuditLogger $auditLogger,
        private readonly QueueTicketStateMachine $queueStateMachine,
        private readonly QueueNumberGenerator $queueNumberGenerator,
        private readonly WorkflowDefinitionService $workflow,
        private readonly ServicePostBehaviorResolver $behaviorResolver,
        private readonly ParticipantServiceWorkflowService $participantServiceWorkflow,
        private readonly WorkflowRealtimePublisher $realtimePublisher,
    ) {}

    /**
     * @return Collection<int, QueueTicket>
     */
    public function ticketsForPost(Event $event, ServicePost $servicePost): Collection
    {
        $relations = [
            'event.settings',
            'eventParticipant.participant',
            'eventParticipant.services',
            'servicePost',
            'calledBy',
        ];

        $active = QueueTicket::query()
            ->where('event_id', $event->id)
            ->where('service_post_id', $servicePost->id)
            ->whereNotIn('status', [
                QueueTicketStatus::Finished->value,
                QueueTicketStatus::Cancelled->value,
            ])
            ->with($relations)
            ->orderByRaw("case status when 'calling' then 0 when 'serving' then 1 when 'waiting' then 2 else 3 end")
            ->orderBy('number')
            ->get();
        $finished = QueueTicket::query()
            ->where('event_id', $event->id)
            ->where('service_post_id', $servicePost->id)
            ->whereIn('status', [
                QueueTicketStatus::Finished->value,
                QueueTicketStatus::Cancelled->value,
            ])
            ->with($relations)
            ->latest('finished_at')
            ->limit(20)
            ->get();

        return $active->concat($finished)->unique('id')->values();
    }

    /**
     * Tickets valid for the waiting-area controls. Terminal tickets must never
     * be offered as a Goto target.
     *
     * @return Collection<int, QueueTicket>
     */
    public function ticketsForWaitingArea(Event $event, ServicePost $servicePost): Collection
    {
        return $this->ticketsForPost($event, $servicePost)
            ->reject(fn (QueueTicket $ticket): bool => in_array($ticket->status, [
                QueueTicketStatus::Finished,
                QueueTicketStatus::Cancelled,
            ], true))
            ->values();
    }

    public function controlPostForWaitingArea(Event $event): ?ServicePost
    {
        return ServicePost::query()
            ->where('event_id', $event->id)
            ->where('is_active', true)
            ->orderByRaw(
                "case behavior when 'health_form' then 0 when 'donation_form' then 1 else 2 end",
            )
            ->orderBy('sequence')
            ->first();
    }

    public function call(Event $event, ServicePost $servicePost, QueueTicket $queueTicket, ?User $actor): QueueTicket
    {
        return $this->updateTicket(
            $event,
            $servicePost,
            $queueTicket,
            $actor,
            QueueTicketStatus::Calling,
            AuditAction::QueueTicketCalled,
            function (QueueTicket $ticket) use ($actor): void {
                $ticket->called_at = now();
                $ticket->called_by = $actor?->id;
            },
            function (Event $event, ServicePost $post, QueueTicket $ticket, EventParticipant $participant): void {
                $this->ensureNoOtherActiveCallInLane($event, $post, $ticket, $participant);
            },
        );
    }

    public function skip(Event $event, ServicePost $servicePost, QueueTicket $queueTicket, ?User $actor): QueueTicket
    {
        return $this->updateTicket(
            $event,
            $servicePost,
            $queueTicket,
            $actor,
            QueueTicketStatus::Skipped,
            AuditAction::QueueTicketSkipped,
            function (QueueTicket $ticket): void {
                $ticket->skipped_at = now();
            },
        );
    }

    public function start(Event $event, ServicePost $servicePost, QueueTicket $queueTicket, User $actor): QueueTicket
    {
        $updatedTicket = $this->database->transaction(function () use ($event, $servicePost, $queueTicket, $actor): QueueTicket {
            $lockedEvent = $this->lockActiveEvent($event);
            [$lockedPost, $lockedTicket, $participant] = $this->lockContext($lockedEvent, $servicePost, $queueTicket);
            $oldValues = $this->ticketSnapshot($lockedTicket);

            if (in_array($lockedTicket->status, [
                QueueTicketStatus::Waiting,
                QueueTicketStatus::Skipped,
            ], true)) {
                $this->queueStateMachine->transition($lockedTicket, QueueTicketStatus::Calling);
            }
            $this->queueStateMachine->transition($lockedTicket, QueueTicketStatus::Serving);
            $lockedTicket->called_at ??= now();
            $lockedTicket->called_by ??= $actor->id;
            $lockedTicket->served_at = now();
            $lockedTicket->save();
            $participant->status = $this->participantServiceWorkflow->start($participant, $lockedPost)
                ?? ParticipantStatus::ServiceInProgress;
            $participant->current_service_post_id = $lockedPost->id;
            $participant->save();
            $this->writeTicketAudit($lockedTicket, $actor, AuditAction::QueueTicketServing, $oldValues);

            return $lockedTicket;
        });

        $this->broadcast($updatedTicket, AuditAction::QueueTicketServing);

        return $updatedTicket;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function complete(
        Event $event,
        ServicePost $servicePost,
        QueueTicket $queueTicket,
        array $attributes,
        User $actor,
    ): QueueTicket {
        $nextPostId = null;
        $alreadyFinished = false;
        $eventParticipantId = null;
        $completedBehavior = null;
        $screeningResult = null;
        $nextBehavior = null;
        $nextQueueTicketId = null;

        $updatedTicket = $this->database->transaction(function () use (
            $event,
            $servicePost,
            $queueTicket,
            $attributes,
            $actor,
            &$nextPostId,
            &$alreadyFinished,
            &$eventParticipantId,
            &$completedBehavior,
            &$screeningResult,
            &$nextBehavior,
            &$nextQueueTicketId,
        ): QueueTicket {
            $lockedEvent = $this->lockActiveEvent($event);
            [$lockedPost, $lockedTicket, $participant] = $this->lockContext($lockedEvent, $servicePost, $queueTicket);

            if ($lockedTicket->status === QueueTicketStatus::Finished) {
                $alreadyFinished = true;

                return $lockedTicket;
            }

            $oldTicketValues = $this->ticketSnapshot($lockedTicket);
            $this->moveTicketToServing($lockedTicket, $actor);
            $startedStatus = $this->participantServiceWorkflow->start($participant, $lockedPost);

            if ($startedStatus !== null) {
                $participant->status = $startedStatus;
            }
            $completion = $this->behaviorResolver
                ->resolve($lockedPost->behavior)
                ->complete($lockedEvent, $participant, $lockedPost, $attributes, $actor);
            $eventParticipantId = $participant->id;
            $completedBehavior = $lockedPost->behavior;
            $screeningResult = $completion->screeningResult;
            $this->queueStateMachine->transition($lockedTicket, QueueTicketStatus::Finished);
            $lockedTicket->finished_at = now();
            $lockedTicket->save();

            $serviceTransition = $this->participantServiceWorkflow->complete(
                $lockedEvent,
                $participant,
                $lockedPost,
                $completion,
            );

            if ($serviceTransition !== null) {
                $nextPost = $serviceTransition->nextServicePost;
                $nextPostId = $nextPost?->id;
                $nextBehavior = $nextPost?->behavior;
                $participant->status = $serviceTransition->participantStatus;
                $participant->current_service_post_id = $nextPost?->id;

                if ($nextPost !== null) {
                    $nextQueueTicketId = $this
                        ->createNextTicket($lockedEvent, $participant, $nextPost)
                        ->id;
                }
            } else {
                $shouldStopLegacyWorkflow = $completion->stopWorkflow
                    || $completion->screeningResult === ScreeningResult::NotEligible;
                $nextPost = $shouldStopLegacyWorkflow
                    ? null
                    : $this->workflow->nextActivePost($lockedEvent, $lockedPost, true);
                $nextPostId = $nextPost?->id;
                $nextBehavior = $nextPost?->behavior;

                if ($shouldStopLegacyWorkflow) {
                    $participant->status = ParticipantStatus::NotEligible;
                    $participant->current_service_post_id = null;
                } elseif ($nextPost === null) {
                    $participant->status = ParticipantStatus::Finished;
                    $participant->current_service_post_id = null;
                } else {
                    $participant->status = ParticipantStatus::WaitingService;
                    $participant->current_service_post_id = $nextPost->id;
                    $nextQueueTicketId = $this
                        ->createNextTicket($lockedEvent, $participant, $nextPost)
                        ->id;
                }
            }
            if ($participant->current_service_post_id === null) {
                $participant->completed_at ??= now();
            }
            $participant->save();

            $this->writeTicketAudit($lockedTicket, $actor, AuditAction::ServicePostCompleted, $oldTicketValues);
            if ($completion->record !== null) {
                $this->auditLogger->record(
                    actor: $actor,
                    subject: $completion->record,
                    action: AuditAction::ServicePostCompleted,
                    eventId: $lockedEvent->id,
                    oldValues: null,
                    newValues: [
                        'service_post_id' => $lockedPost->id,
                        'next_service_post_id' => $nextPostId,
                        'participant_status' => $participant->status->value,
                    ],
                );
            }

            return $lockedTicket;
        });

        if (! $alreadyFinished) {
            if ($eventParticipantId !== null && $completedBehavior !== null) {
                $this->realtimePublisher->serviceCompleted(
                    $updatedTicket,
                    $eventParticipantId,
                    $completedBehavior,
                    $screeningResult,
                    $nextBehavior,
                    $nextPostId,
                    $nextQueueTicketId,
                );
            }
            $this->broadcast($updatedTicket, AuditAction::ServicePostCompleted, $nextPostId);
        }

        return $updatedTicket;
    }

    public function cancel(
        Event $event,
        ServicePost $servicePost,
        QueueTicket $queueTicket,
        User $actor,
    ): QueueTicket {
        $nextPostId = null;
        $eventParticipantId = null;
        $nextBehavior = null;
        $nextQueueTicketId = null;

        $cancelledTicket = $this->database->transaction(function () use (
            $event,
            $servicePost,
            $queueTicket,
            $actor,
            &$nextPostId,
            &$eventParticipantId,
            &$nextBehavior,
            &$nextQueueTicketId,
        ): QueueTicket {
            $lockedEvent = $this->lockActiveEvent($event);
            [$lockedPost, $lockedTicket, $participant] = $this->lockContext(
                $lockedEvent,
                $servicePost,
                $queueTicket,
            );
            $oldValues = $this->ticketSnapshot($lockedTicket);
            $this->queueStateMachine->transition($lockedTicket, QueueTicketStatus::Cancelled);
            $lockedTicket->active_number = null;
            $lockedTicket->cancelled_at = now();
            $lockedTicket->cancelled_by = $actor->id;
            $lockedTicket->save();

            $transition = $this->participantServiceWorkflow->cancel(
                $lockedEvent,
                $participant,
                $lockedPost,
            );

            if ($transition !== null) {
                $nextPost = $transition->nextServicePost;
                $eventParticipantId = $participant->id;
                $nextPostId = $nextPost?->id;
                $nextBehavior = $nextPost?->behavior;
                $participant->status = $transition->participantStatus;
                $participant->current_service_post_id = $nextPost?->id;

                if ($nextPost !== null) {
                    $nextQueueTicketId = $this
                        ->createNextTicket($lockedEvent, $participant, $nextPost)
                        ->id;
                } else {
                    $participant->completed_at ??= now();
                }

                $participant->save();
            }

            $this->writeTicketAudit(
                $lockedTicket,
                $actor,
                AuditAction::QueueTicketCancelled,
                $oldValues,
            );

            return $lockedTicket;
        });

        if ($eventParticipantId !== null
            && $nextPostId !== null
            && $nextQueueTicketId !== null
            && $nextBehavior === ServicePostBehavior::HealthForm
        ) {
            $this->realtimePublisher->movedToHealthCheck(
                $cancelledTicket,
                $eventParticipantId,
                $nextPostId,
                $nextQueueTicketId,
            );
        }
        $this->broadcast($cancelledTicket, AuditAction::QueueTicketCancelled, $nextPostId);

        return $cancelledTicket;
    }

    /**
     * @param  callable(QueueTicket): mixed  $mutate
     * @param  (callable(Event, ServicePost, QueueTicket, EventParticipant): mixed)|null  $beforeTransition
     */
    private function updateTicket(
        Event $event,
        ServicePost $servicePost,
        QueueTicket $queueTicket,
        ?User $actor,
        QueueTicketStatus $status,
        AuditAction $action,
        callable $mutate,
        ?callable $beforeTransition = null,
    ): QueueTicket {
        $updatedTicket = $this->database->transaction(function () use (
            $event,
            $servicePost,
            $queueTicket,
            $actor,
            $status,
            $action,
            $mutate,
            $beforeTransition,
        ): QueueTicket {
            $lockedEvent = $this->lockActiveEvent($event);
            [$lockedPost, $lockedTicket, $participant] = $this->lockContext($lockedEvent, $servicePost, $queueTicket);
            $oldValues = $this->ticketSnapshot($lockedTicket);
            if ($beforeTransition !== null) {
                $beforeTransition($lockedEvent, $lockedPost, $lockedTicket, $participant);
            }
            $this->queueStateMachine->transition($lockedTicket, $status);
            $mutate($lockedTicket);
            $lockedTicket->save();
            $this->writeTicketAudit($lockedTicket, $actor, $action, $oldValues);

            return $lockedTicket;
        });

        $this->broadcast($updatedTicket, $action);

        return $updatedTicket;
    }

    private function lockActiveEvent(Event $event): Event
    {
        $lockedEvent = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

        if ($lockedEvent->status !== EventStatus::Active || $lockedEvent->active_marker !== 'active') {
            throw ValidationException::withMessages(['event' => 'Antrean hanya dapat dikelola pada event aktif.']);
        }

        return $lockedEvent;
    }

    /**
     * @return array{ServicePost, QueueTicket, EventParticipant}
     */
    private function lockContext(Event $event, ServicePost $servicePost, QueueTicket $queueTicket): array
    {
        $lockedPost = ServicePost::query()
            ->where('event_id', $event->id)
            ->whereKey($servicePost->id)
            ->where('is_active', true)
            ->lockForUpdate()
            ->firstOrFail();
        $lockedTicket = QueueTicket::query()
            ->where('event_id', $event->id)
            ->where('service_post_id', $lockedPost->id)
            ->whereKey($queueTicket->id)
            ->lockForUpdate()
            ->firstOrFail();
        $participant = EventParticipant::query()
            ->where('event_id', $event->id)
            ->whereKey($lockedTicket->event_participant_id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($participant->current_service_post_id !== $lockedPost->id) {
            throw ValidationException::withMessages([
                'queue_ticket' => 'Peserta tidak lagi berada pada pos pelayanan ini.',
            ]);
        }

        return [$lockedPost, $lockedTicket, $participant];
    }

    private function ensureNoOtherActiveCallInLane(
        Event $event,
        ServicePost $servicePost,
        QueueTicket $queueTicket,
        EventParticipant $participant,
    ): void {
        $participant->loadMissing('participant');

        $hasActiveCall = QueueTicket::query()
            ->where('event_id', $event->id)
            ->where('service_post_id', $servicePost->id)
            ->where('id', '!=', $queueTicket->id)
            ->whereIn('status', [
                QueueTicketStatus::Calling->value,
                QueueTicketStatus::Serving->value,
            ])
            ->whereHas(
                'eventParticipant.participant',
                fn ($query) => $query->where('gender', $participant->participant->gender->value),
            )
            ->exists();

        if ($hasActiveCall) {
            throw ValidationException::withMessages([
                'queue_ticket' => 'Masih ada nomor pada jalur gender ini yang sedang dipanggil.',
            ]);
        }
    }

    private function moveTicketToServing(QueueTicket $ticket, User $actor): void
    {
        if ($ticket->status === QueueTicketStatus::Waiting) {
            $this->queueStateMachine->transition($ticket, QueueTicketStatus::Calling);
        } elseif ($ticket->status === QueueTicketStatus::Skipped) {
            $this->queueStateMachine->transition($ticket, QueueTicketStatus::Calling);
        }
        if ($ticket->status === QueueTicketStatus::Calling) {
            $this->queueStateMachine->transition($ticket, QueueTicketStatus::Serving);
        }

        $ticket->called_at ??= now();
        $ticket->called_by ??= $actor->id;
        $ticket->served_at ??= now();
    }

    private function createNextTicket(
        Event $event,
        EventParticipant $participant,
        ServicePost $servicePost,
    ): QueueTicket {
        $existing = QueueTicket::query()
            ->where('event_participant_id', $participant->id)
            ->where('service_post_id', $servicePost->id)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $queueType = QueueType::General;
        $number = null;

        if ($servicePost->behavior === ServicePostBehavior::DonationForm) {
            $participant->loadMissing('participant');
            $donorNumber = $this->queueNumberGenerator->nextDonor(
                $event,
                $participant->participant->gender,
            );
            $queueType = $donorNumber->queueType;
            $number = $donorNumber->number;
        }

        return QueueTicket::query()->create([
            'event_id' => $event->id,
            'event_participant_id' => $participant->id,
            'service_post_id' => $servicePost->id,
            'queue_type' => $queueType,
            'number' => $number ?? $this->queueNumberGenerator->next($event, $servicePost),
            'status' => QueueTicketStatus::Waiting,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function ticketSnapshot(QueueTicket $ticket): array
    {
        return [
            'status' => $ticket->status->value,
            'called_at' => $ticket->called_at?->toIso8601String(),
            'served_at' => $ticket->served_at?->toIso8601String(),
            'skipped_at' => $ticket->skipped_at?->toIso8601String(),
            'finished_at' => $ticket->finished_at?->toIso8601String(),
            'cancelled_at' => $ticket->cancelled_at?->toIso8601String(),
            'cancelled_by' => $ticket->cancelled_by,
        ];
    }

    /**
     * @param  array<string, mixed>  $oldValues
     */
    private function writeTicketAudit(QueueTicket $ticket, ?User $actor, AuditAction $action, array $oldValues): void
    {
        $this->auditLogger->record(
            actor: $actor,
            subject: $ticket,
            action: $action,
            eventId: $ticket->event_id,
            oldValues: $oldValues,
            newValues: $this->ticketSnapshot($ticket),
        );
    }

    private function broadcast(QueueTicket $ticket, AuditAction $action, ?int $nextPostId = null): void
    {
        $this->realtimePublisher->queueChanged($ticket, $action);
        ServiceQueueUpdated::dispatch(
            eventId: $ticket->event_id,
            servicePostId: $ticket->service_post_id,
            queueTicketId: $ticket->id,
            action: $action,
            nextServicePostId: $nextPostId,
        );
    }
}
