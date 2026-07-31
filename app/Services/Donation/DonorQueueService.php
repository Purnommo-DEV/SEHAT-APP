<?php

namespace App\Services\Donation;

use App\Enums\AuditAction;
use App\Enums\EventStatus;
use App\Enums\ParticipantStatus;
use App\Enums\QueueTicketStatus;
use App\Enums\ServicePostBehavior;
use App\Events\DonorQueueUpdated;
use App\Models\Event;
use App\Models\EventParticipant;
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

class DonorQueueService
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
            'servicePost',
            'calledBy',
        ];
        $activeTickets = QueueTicket::query()
            ->where('event_id', $event->id)
            ->whereHas('servicePost', fn ($query) => $this->legacyPostBehavior->apply($query, ServicePostBehavior::DonationForm))
            ->whereNotIn('status', [
                QueueTicketStatus::Finished->value,
                QueueTicketStatus::Cancelled->value,
            ])
            ->with($relations)
            ->orderBy('queue_type')
            ->orderBy('number')
            ->get();
        $finishedTickets = QueueTicket::query()
            ->where('event_id', $event->id)
            ->whereHas('servicePost', fn ($query) => $this->legacyPostBehavior->apply($query, ServicePostBehavior::DonationForm))
            ->where('status', QueueTicketStatus::Finished->value)
            ->with($relations)
            ->latest('finished_at')
            ->limit(20)
            ->get();

        return $activeTickets->concat($finishedTickets)->unique('id')->values();
    }

    public function call(Event $event, QueueTicket $queueTicket, User $actor): QueueTicket
    {
        if ($this->usesCanonicalWorkflow($queueTicket)) {
            $updatedTicket = $this->serviceQueueService->call(
                $event,
                $this->canonicalDonationPost($event, $queueTicket),
                $queueTicket,
                $actor,
            );
            $this->broadcastLegacy($updatedTicket, AuditAction::QueueTicketCalled);

            return $updatedTicket;
        }

        return $this->updateTicket(
            $event,
            $queueTicket,
            $actor,
            QueueTicketStatus::Calling,
            AuditAction::QueueTicketCalled,
            function (QueueTicket $ticket) use ($actor): void {
                $ticket->called_at = now();
                $ticket->called_by = $actor->id;
            },
            true,
        );
    }

    public function skip(Event $event, QueueTicket $queueTicket, User $actor): QueueTicket
    {
        if ($this->usesCanonicalWorkflow($queueTicket)) {
            $updatedTicket = $this->serviceQueueService->skip(
                $event,
                $this->canonicalDonationPost($event, $queueTicket),
                $queueTicket,
                $actor,
            );
            $this->broadcastLegacy($updatedTicket, AuditAction::QueueTicketSkipped);

            return $updatedTicket;
        }

        return $this->updateTicket(
            $event,
            $queueTicket,
            $actor,
            QueueTicketStatus::Skipped,
            AuditAction::QueueTicketSkipped,
            function (): void {},
            true,
        );
    }

    public function start(Event $event, QueueTicket $queueTicket, User $actor): QueueTicket
    {
        if ($this->usesCanonicalWorkflow($queueTicket)) {
            $updatedTicket = $this->serviceQueueService->start(
                $event,
                $this->canonicalDonationPost($event, $queueTicket),
                $queueTicket,
                $actor,
            );
            $this->broadcastLegacy($updatedTicket, AuditAction::DonationStarted);

            return $updatedTicket;
        }

        $updatedTicket = $this->database->transaction(function () use ($event, $queueTicket, $actor): QueueTicket {
            $lockedEvent = $this->lockActiveEvent($event);
            $lockedTicket = $this->lockDonationTicket($lockedEvent, $queueTicket, true);
            $eventParticipant = $this->lockEventParticipant($lockedTicket);
            $oldValues = $this->snapshot($lockedTicket);

            $this->queueStateMachine->transition($lockedTicket, QueueTicketStatus::Serving);
            $this->participantStateMachine->transition($eventParticipant, ParticipantStatus::DonationInProgress);
            $lockedTicket->served_at = now();
            $lockedTicket->save();
            $eventParticipant->save();
            $this->writeAudit($lockedTicket, $actor, AuditAction::DonationStarted, $oldValues);

            return $lockedTicket;
        });

        $this->broadcast($updatedTicket, AuditAction::DonationStarted);

        return $updatedTicket;
    }

    public function complete(Event $event, QueueTicket $queueTicket, User $actor): QueueTicket
    {
        if ($this->usesCanonicalWorkflow($queueTicket)) {
            $finishedTicket = QueueTicket::query()
                ->where('event_id', $event->id)
                ->whereKey($queueTicket->id)
                ->where('status', QueueTicketStatus::Finished->value)
                ->first();

            if ($finishedTicket !== null) {
                return $finishedTicket;
            }

            $updatedTicket = $this->serviceQueueService->complete(
                $event,
                $this->canonicalDonationPost($event, $queueTicket),
                $queueTicket,
                [],
                $actor,
            );

            $this->broadcastLegacy($updatedTicket, AuditAction::DonationCompleted);

            return $updatedTicket;
        }

        $wasAlreadyFinished = false;
        $eventParticipantId = null;
        $nextPost = null;
        $updatedTicket = $this->database->transaction(function () use (
            $event,
            $queueTicket,
            $actor,
            &$wasAlreadyFinished,
            &$eventParticipantId,
            &$nextPost,
        ): QueueTicket {
            $lockedEvent = $this->lockActiveEvent($event);
            $lockedTicket = $this->lockDonationTicket($lockedEvent, $queueTicket);

            if ($lockedTicket->status === QueueTicketStatus::Finished) {
                $wasAlreadyFinished = true;

                return $lockedTicket;
            }

            $eventParticipant = $this->lockEventParticipant($lockedTicket);
            $eventParticipantId = $eventParticipant->id;
            $oldValues = $this->snapshot($lockedTicket);
            $this->queueStateMachine->transition($lockedTicket, QueueTicketStatus::Finished);
            $this->participantStateMachine->transition($eventParticipant, ParticipantStatus::DonationCompleted);
            $nextPost = $this->workflow->nextActivePost($lockedEvent, $lockedTicket->servicePost, true);

            $lockedTicket->finished_at = now();
            $lockedTicket->save();
            $eventParticipant->current_service_post_id = $nextPost?->id;
            $eventParticipant->save();
            $this->writeAudit($lockedTicket, $actor, AuditAction::DonationCompleted, $oldValues);

            return $lockedTicket;
        });

        if (! $wasAlreadyFinished) {
            if ($eventParticipantId === null) {
                throw new \LogicException('Participant context was not captured for donation completion.');
            }

            $this->realtimePublisher->serviceCompleted(
                $updatedTicket,
                $eventParticipantId,
                ServicePostBehavior::DonationForm,
                null,
                $nextPost === null ? null : $this->legacyPostBehavior->resolve($nextPost),
                $nextPost?->id,
                null,
            );
            $this->broadcast($updatedTicket, AuditAction::DonationCompleted);
        }

        return $updatedTicket;
    }

    /**
     * @param  callable(QueueTicket): void  $mutate
     */
    private function updateTicket(
        Event $event,
        QueueTicket $queueTicket,
        User $actor,
        QueueTicketStatus $nextStatus,
        AuditAction $action,
        callable $mutate,
        bool $requireActivePost,
    ): QueueTicket {
        $updatedTicket = $this->database->transaction(function () use (
            $event,
            $queueTicket,
            $actor,
            $nextStatus,
            $action,
            $mutate,
            $requireActivePost,
        ): QueueTicket {
            $lockedEvent = $this->lockActiveEvent($event);
            $lockedTicket = $this->lockDonationTicket($lockedEvent, $queueTicket, $requireActivePost);
            $this->lockEventParticipant($lockedTicket);
            $oldValues = $this->snapshot($lockedTicket);
            $this->queueStateMachine->transition($lockedTicket, $nextStatus);
            $mutate($lockedTicket);
            $lockedTicket->save();
            $this->writeAudit($lockedTicket, $actor, $action, $oldValues);

            return $lockedTicket;
        });

        $this->broadcast($updatedTicket, $action);

        return $updatedTicket;
    }

    private function lockActiveEvent(Event $event): Event
    {
        $lockedEvent = Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

        if ($lockedEvent->status !== EventStatus::Active || $lockedEvent->active_marker !== 'active') {
            throw ValidationException::withMessages([
                'event' => 'Antrean donor hanya dapat dikelola pada event yang sedang aktif.',
            ]);
        }

        return $lockedEvent;
    }

    private function lockDonationTicket(Event $event, QueueTicket $queueTicket, bool $requireActivePost = false): QueueTicket
    {
        $lockedTicket = QueueTicket::query()
            ->where('event_id', $event->id)
            ->whereKey($queueTicket->getKey())
            ->lockForUpdate()
            ->firstOrFail();
        $post = ServicePost::query()->whereKey($lockedTicket->service_post_id)->lockForUpdate()->firstOrFail();

        if (! $this->legacyPostBehavior->matches($post, ServicePostBehavior::DonationForm)
            || ($requireActivePost && ! $post->is_active)) {
            throw ValidationException::withMessages([
                'queue_ticket' => 'Tiket tidak berada pada Pos Donor yang aktif.',
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
    private function snapshot(QueueTicket $queueTicket): array
    {
        return [
            'status' => $queueTicket->status->value,
            'called_at' => $queueTicket->called_at?->toIso8601String(),
            'served_at' => $queueTicket->served_at?->toIso8601String(),
            'finished_at' => $queueTicket->finished_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $oldValues
     */
    private function writeAudit(
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
            newValues: $this->snapshot($queueTicket),
        );
    }

    private function broadcast(QueueTicket $queueTicket, AuditAction $action): void
    {
        $this->realtimePublisher->queueChanged($queueTicket, $action);
        $this->broadcastLegacy($queueTicket, $action);
    }

    private function broadcastLegacy(QueueTicket $queueTicket, AuditAction $action): void
    {
        DonorQueueUpdated::dispatch($queueTicket->event_id, $queueTicket->id, $action);
    }

    private function usesCanonicalWorkflow(QueueTicket $queueTicket): bool
    {
        return EventParticipant::query()
            ->whereKey($queueTicket->event_participant_id)
            ->whereHas('services')
            ->exists();
    }

    private function canonicalDonationPost(Event $event, QueueTicket $queueTicket): ServicePost
    {
        $servicePost = ServicePost::query()
            ->where('event_id', $event->id)
            ->whereKey($queueTicket->service_post_id)
            ->firstOrFail();

        if ($servicePost->behavior !== ServicePostBehavior::DonationForm) {
            throw ValidationException::withMessages([
                'queue_ticket' => 'Peserta SOP baru wajib diproses melalui Pos Donor kanonik.',
            ]);
        }

        return $servicePost;
    }
}
