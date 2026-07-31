<?php

namespace App\Services\Screening;

use App\Data\ScreeningDecisionResult;
use App\Enums\AuditAction;
use App\Enums\EventStatus;
use App\Enums\ParticipantStatus;
use App\Enums\QueueTicketStatus;
use App\Enums\ScreeningResult;
use App\Enums\ServicePostBehavior;
use App\Events\DonorQueueUpdated;
use App\Events\ScreeningUpdated;
use App\Models\DonorScreening;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\QueueTicket;
use App\Models\ServicePost;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Queue\QueueNumberGenerator;
use App\Services\Realtime\WorkflowRealtimePublisher;
use App\Services\Workflow\LegacyServicePostBehaviorResolver;
use App\Services\Workflow\ParticipantStateMachine;
use App\Services\Workflow\ServiceQueueService;
use App\Services\Workflow\WorkflowDefinitionService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class DonorScreeningService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly AuditLogger $auditLogger,
        private readonly ParticipantStateMachine $participantStateMachine,
        private readonly QueueNumberGenerator $queueNumberGenerator,
        private readonly WorkflowDefinitionService $workflow,
        private readonly LegacyServicePostBehaviorResolver $legacyPostBehavior,
        private readonly ServiceQueueService $serviceQueueService,
        private readonly WorkflowRealtimePublisher $realtimePublisher,
    ) {}

    /**
     * @return Collection<int, EventParticipant>
     */
    public function participantsForEvent(Event $event): Collection
    {
        $relations = [
            'participant',
            'currentServicePost',
            'healthAssessment',
            'latestDonorScreening.screenedBy',
        ];
        $waiting = EventParticipant::query()
            ->where('event_id', $event->id)
            ->where('status', ParticipantStatus::WaitingScreening->value)
            ->with($relations)
            ->oldest('updated_at')
            ->get();
        $decided = EventParticipant::query()
            ->where('event_id', $event->id)
            ->whereIn('status', [
                ParticipantStatus::WaitingDonor->value,
                ParticipantStatus::NotEligible->value,
            ])
            ->whereHas('latestDonorScreening')
            ->with($relations)
            ->latest('updated_at')
            ->limit(20)
            ->get();

        return $waiting->concat($decided)->unique('id')->values();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function decide(
        Event $event,
        EventParticipant $eventParticipant,
        array $attributes,
        User $actor,
    ): ScreeningDecisionResult {
        if ($eventParticipant->services()->exists()) {
            return $this->decideCanonical($event, $eventParticipant, $attributes, $actor);
        }

        /** @var ScreeningDecisionResult $decision */
        $decision = $this->database->transaction(function () use ($event, $eventParticipant, $attributes, $actor): ScreeningDecisionResult {
            $lockedEvent = Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureActiveEvent($lockedEvent);
            $lockedParticipant = EventParticipant::query()
                ->where('event_id', $lockedEvent->id)
                ->whereKey($eventParticipant->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->ensureLegacyParticipant($lockedParticipant);
            $existingScreening = DonorScreening::query()
                ->where('event_participant_id', $lockedParticipant->id)
                ->lockForUpdate()
                ->first();

            if ($existingScreening !== null) {
                $existingTicket = QueueTicket::query()
                    ->where('event_participant_id', $lockedParticipant->id)
                    ->whereHas('servicePost', fn ($query) => $this->legacyPostBehavior->apply($query, ServicePostBehavior::DonationForm))
                    ->first();

                return new ScreeningDecisionResult($existingScreening, true, $existingTicket);
            }

            if ($lockedParticipant->status !== ParticipantStatus::WaitingScreening) {
                throw ValidationException::withMessages([
                    'event_participant' => 'Peserta belum berada pada antrean screening.',
                ]);
            }

            $screeningPost = ServicePost::query()
                ->where('event_id', $lockedEvent->id)
                ->whereKey($lockedParticipant->current_service_post_id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if ($screeningPost === null
                || ! $this->legacyPostBehavior->matches($screeningPost, ServicePostBehavior::ScreeningForm)) {
                throw ValidationException::withMessages([
                    'event_participant' => 'Peserta tidak berada pada Pos Screening Donor yang aktif.',
                ]);
            }

            $result = ScreeningResult::from($attributes['result']);
            $destinationPost = $this->destinationPost($lockedEvent, $screeningPost, $result);
            $nextStatus = $result === ScreeningResult::Eligible
                ? ParticipantStatus::WaitingDonor
                : ParticipantStatus::NotEligible;
            $oldStatus = $lockedParticipant->status;
            $this->participantStateMachine->transition($lockedParticipant, $nextStatus);

            $screening = DonorScreening::query()->create([
                'event_id' => $lockedEvent->id,
                'event_participant_id' => $lockedParticipant->id,
                'service_post_id' => $screeningPost->id,
                'result' => $result,
                'reason' => $result === ScreeningResult::NotEligible ? ($attributes['reason'] ?? null) : null,
                'screened_by' => $actor->id,
            ]);
            $lockedParticipant->current_service_post_id = $destinationPost?->id;
            $lockedParticipant->save();
            $donorQueueTicket = null;

            if ($result === ScreeningResult::Eligible) {
                $lockedParticipant->loadMissing('participant');
                $donorNumber = $this->queueNumberGenerator->nextDonor(
                    $lockedEvent,
                    $lockedParticipant->participant->gender,
                );
                $donorQueueTicket = QueueTicket::query()->create([
                    'event_id' => $lockedEvent->id,
                    'event_participant_id' => $lockedParticipant->id,
                    'service_post_id' => $destinationPost->id,
                    'queue_type' => $donorNumber->queueType,
                    'number' => $donorNumber->number,
                    'status' => QueueTicketStatus::Waiting,
                ]);
            }

            $action = $result === ScreeningResult::Eligible
                ? AuditAction::ScreeningEligible
                : AuditAction::ScreeningNotEligible;
            $this->auditLogger->record(
                actor: $actor,
                subject: $screening,
                action: $action,
                eventId: $lockedEvent->id,
                oldValues: ['participant_status' => $oldStatus->value],
                newValues: [
                    'result' => $screening->result->value,
                    'reason' => $screening->reason,
                    'participant_status' => $lockedParticipant->status->value,
                    'current_service_post_id' => $lockedParticipant->current_service_post_id,
                    'donor_queue_ticket_id' => $donorQueueTicket?->id,
                    'donor_queue_number' => $donorQueueTicket?->formattedNumber(),
                ],
            );

            if ($donorQueueTicket !== null) {
                $this->auditLogger->record(
                    actor: $actor,
                    subject: $donorQueueTicket,
                    action: AuditAction::DonorTicketIssued,
                    eventId: $lockedEvent->id,
                    oldValues: null,
                    newValues: [
                        'queue_type' => $donorQueueTicket->queue_type->value,
                        'number' => $donorQueueTicket->number,
                        'formatted_number' => $donorQueueTicket->formattedNumber(),
                    ],
                );
            }

            return new ScreeningDecisionResult($screening, false, $donorQueueTicket);
        });

        if (! $decision->alreadyDecided) {
            $screeningPost = ServicePost::query()
                ->whereKey($decision->screening->service_post_id)
                ->firstOrFail();
            $participant = EventParticipant::query()
                ->whereKey($decision->screening->event_participant_id)
                ->firstOrFail();
            $destinationPost = $participant->current_service_post_id === null
                ? null
                : ServicePost::query()->whereKey($participant->current_service_post_id)->first();
            $screeningTicket = QueueTicket::query()
                ->where('event_participant_id', $participant->id)
                ->where('service_post_id', $screeningPost->id)
                ->latest('id')
                ->first();
            $action = $decision->screening->result === ScreeningResult::Eligible
                ? AuditAction::ScreeningEligible
                : AuditAction::ScreeningNotEligible;

            $this->realtimePublisher->serviceCompletedForParticipant(
                $decision->screening->event_id,
                $participant->id,
                $screeningTicket?->id,
                ServicePostBehavior::ScreeningForm,
                $decision->screening->result,
                $destinationPost === null ? null : $this->legacyPostBehavior->resolve($destinationPost),
                $destinationPost?->id,
                $decision->donorQueueTicket?->id,
            );
            $snapshotTicket = $decision->donorQueueTicket ?? $screeningTicket;
            $this->realtimePublisher->queueStateChanged(
                $decision->screening->event_id,
                $action,
                $participant->id,
                $snapshotTicket?->id,
                $snapshotTicket === null ? $screeningPost->id : $snapshotTicket->service_post_id,
            );
            ScreeningUpdated::dispatch(
                $decision->screening->event_id,
                $decision->screening->event_participant_id,
                $decision->screening->result,
            );
            if ($decision->donorQueueTicket !== null) {
                DonorQueueUpdated::dispatch(
                    $decision->donorQueueTicket->event_id,
                    $decision->donorQueueTicket->id,
                    AuditAction::DonorTicketIssued,
                );
            }
        }

        return $decision;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function decideCanonical(
        Event $event,
        EventParticipant $eventParticipant,
        array $attributes,
        User $actor,
    ): ScreeningDecisionResult {
        $existingScreening = DonorScreening::query()
            ->where('event_id', $event->id)
            ->where('event_participant_id', $eventParticipant->id)
            ->latest('id')
            ->first();

        if ($existingScreening !== null) {
            return new ScreeningDecisionResult(
                $existingScreening,
                true,
                $this->canonicalDonationTicket($event, $eventParticipant),
            );
        }

        $screeningPost = ServicePost::query()
            ->where('event_id', $event->id)
            ->whereKey($eventParticipant->current_service_post_id)
            ->firstOrFail();

        if ($screeningPost->behavior !== ServicePostBehavior::ScreeningForm) {
            throw ValidationException::withMessages([
                'event_participant' => 'Peserta SOP baru wajib diproses melalui Pos Kelayakan Donor kanonik.',
            ]);
        }

        $screeningTicket = QueueTicket::query()
            ->where('event_id', $event->id)
            ->where('event_participant_id', $eventParticipant->id)
            ->where('service_post_id', $screeningPost->id)
            ->firstOrFail();

        $this->serviceQueueService->complete(
            $event,
            $screeningPost,
            $screeningTicket,
            $attributes,
            $actor,
        );

        $screening = DonorScreening::query()
            ->where('event_id', $event->id)
            ->where('event_participant_id', $eventParticipant->id)
            ->where('service_post_id', $screeningPost->id)
            ->latest('id')
            ->firstOrFail();
        $donorQueueTicket = $this->canonicalDonationTicket($event, $eventParticipant);

        ScreeningUpdated::dispatch($event->id, $eventParticipant->id, $screening->result);
        if ($donorQueueTicket !== null) {
            DonorQueueUpdated::dispatch(
                $event->id,
                $donorQueueTicket->id,
                AuditAction::DonorTicketIssued,
            );
        }

        return new ScreeningDecisionResult($screening, false, $donorQueueTicket);
    }

    private function canonicalDonationTicket(
        Event $event,
        EventParticipant $eventParticipant,
    ): ?QueueTicket {
        return QueueTicket::query()
            ->where('event_id', $event->id)
            ->where('event_participant_id', $eventParticipant->id)
            ->whereHas(
                'servicePost',
                fn ($query) => $query->where('behavior', ServicePostBehavior::DonationForm->value),
            )
            ->latest('id')
            ->first();
    }

    private function ensureLegacyParticipant(EventParticipant $eventParticipant): void
    {
        if ($eventParticipant->services()->exists()) {
            throw ValidationException::withMessages([
                'event_participant' => 'Peserta SOP baru tidak boleh diproses oleh workflow screening legacy.',
            ]);
        }
    }

    private function destinationPost(
        Event $event,
        ServicePost $screeningPost,
        ScreeningResult $result,
    ): ?ServicePost {
        $post = $this->workflow->nextActivePost($event, $screeningPost, true);

        if ($result === ScreeningResult::Eligible && $post === null) {
            throw ValidationException::withMessages([
                'event' => 'Pos pelayanan aktif berikutnya harus tersedia sebelum peserta dapat dinyatakan layak donor melalui rute kompatibilitas ini.',
            ]);
        }

        return $post;
    }

    private function ensureActiveEvent(Event $event): void
    {
        if ($event->status !== EventStatus::Active || $event->active_marker !== 'active') {
            throw ValidationException::withMessages([
                'event' => 'Screening hanya dapat dikelola pada event yang sedang aktif.',
            ]);
        }
    }
}
