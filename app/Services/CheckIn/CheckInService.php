<?php

namespace App\Services\CheckIn;

use App\Data\CheckInResult;
use App\Enums\AuditAction;
use App\Enums\EventStatus;
use App\Enums\ParticipantServiceStatus;
use App\Enums\ParticipantServiceType;
use App\Enums\ParticipantStatus;
use App\Enums\QueueTicketStatus;
use App\Enums\QueueType;
use App\Events\ParticipantCheckedIn;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventParticipantStatusHistory;
use App\Models\Participant;
use App\Models\QueueTicket;
use App\Models\ServicePost;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Queue\QueueNumberGenerator;
use App\Services\Queue\RegistrationNumberGenerator;
use App\Services\Realtime\WorkflowRealtimePublisher;
use App\Services\Workflow\ParticipantServiceWorkflowService;
use App\Services\Workflow\QueueTicketStateMachine;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;

class CheckInService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly AuditLogger $auditLogger,
        private readonly ParticipantServiceWorkflowService $participantServiceWorkflow,
        private readonly QueueNumberGenerator $queueNumberGenerator,
        private readonly RegistrationNumberGenerator $registrationNumberGenerator,
        private readonly QueueTicketStateMachine $queueStateMachine,
        private readonly WorkflowRealtimePublisher $realtimePublisher,
    ) {}

    /**
     * @param  list<ParticipantServiceType|string>  $services
     */
    public function checkIn(
        Event $event,
        Participant $participant,
        ?User $actor,
        array $services = [ParticipantServiceType::Donor],
    ): CheckInResult {
        $serviceTypes = $this->normalizeServiceTypes($services);

        /** @var CheckInResult $result */
        $result = $this->database->transaction(function () use ($event, $participant, $actor, $serviceTypes): CheckInResult {
            $lockedEvent = Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureActiveEvent($lockedEvent);
            $settings = $lockedEvent->settings()->lockForUpdate()->firstOrFail();

            $lockedParticipant = Participant::query()->whereKey($participant->getKey())->lockForUpdate()->firstOrFail();
            $eventParticipant = EventParticipant::query()
                ->where('event_id', $lockedEvent->id)
                ->where('participant_id', $lockedParticipant->id)
                ->lockForUpdate()
                ->first();

            if ($eventParticipant?->checked_in_at !== null) {
                if ($eventParticipant->status === ParticipantStatus::Cancelled) {
                    throw ValidationException::withMessages([
                        'participant_id' => 'Registrasi peserta ini telah dibatalkan dan tetap disimpan sebagai histori.',
                    ]);
                }

                $existingTicket = $this->existingQueueTicket($eventParticipant);

                if ($existingTicket instanceof QueueTicket) {
                    return new CheckInResult(
                        $eventParticipant,
                        $existingTicket,
                        $eventParticipant->formattedRegistrationNumber($settings) ?? $existingTicket->formattedNumber(),
                        true,
                    );
                }

                if ($eventParticipant->status !== ParticipantStatus::Waiting) {
                    throw ValidationException::withMessages([
                        'participant_id' => 'Riwayat antrean peserta tidak lengkap dan tidak dapat dibuat ulang setelah proses pelayanan dimulai.',
                    ]);
                }

                if ($eventParticipant->registration_number === null) {
                    $eventParticipant->registration_number = $this->registrationNumberGenerator->next(
                        $lockedEvent,
                        $lockedParticipant->gender,
                    );
                    $eventParticipant->active_registration_number = $eventParticipant->registration_number;
                }

                if ($eventParticipant->registration_number_scope === null) {
                    $eventParticipant->registration_number_scope = EventParticipant::registrationNumberScopeFor(
                        $lockedParticipant->gender,
                    );
                }

                $eventParticipant->registration_order ??= $this->nextRegistrationOrder($lockedEvent);

                $selectedServices = $this->selectedServices($eventParticipant);
                $recoveryServices = $selectedServices === [] ? $serviceTypes : $selectedServices;
                $initialRoute = $this->participantServiceWorkflow->registrationRoute(
                    $lockedEvent,
                    $recoveryServices,
                    true,
                );

                if ($selectedServices === []) {
                    $this->syncSelectedServices($eventParticipant, $recoveryServices);
                }

                $eventParticipant->current_service_post_id = $initialRoute->servicePost->id;
                $eventParticipant->save();
                $recoveredTicket = $this->createInitialQueueTicket(
                    $lockedEvent,
                    $eventParticipant,
                    $initialRoute->servicePost,
                );
                $recoveredTicket->load(['event.settings', 'eventParticipant.participant', 'eventParticipant.services', 'servicePost']);
                $eventParticipant->load(['participant', 'event', 'currentServicePost', 'checkedInBy', 'services']);

                $this->auditLogger->record(
                    actor: $actor,
                    subject: $eventParticipant,
                    action: AuditAction::ParticipantCheckedIn,
                    eventId: $lockedEvent->id,
                    oldValues: ['queue_ticket_id' => null],
                    newValues: [
                        'registration_number' => $eventParticipant->formattedRegistrationNumber($settings),
                        'queue_ticket_id' => $recoveredTicket->id,
                        'queue_number' => $recoveredTicket->formattedNumber(),
                        'recovered_missing_initial_ticket' => true,
                    ],
                );

                return new CheckInResult(
                    $eventParticipant,
                    $recoveredTicket,
                    $eventParticipant->formattedRegistrationNumber($settings) ?? $recoveredTicket->formattedNumber(),
                    true,
                    true,
                );
            }

            $initialRoute = $this->participantServiceWorkflow->registrationRoute(
                $lockedEvent,
                $serviceTypes,
                true,
            );

            if ($eventParticipant === null) {
                $eventParticipant = EventParticipant::query()->create([
                    'event_id' => $lockedEvent->id,
                    'participant_id' => $lockedParticipant->id,
                    'status' => ParticipantStatus::Registered,
                ]);
            }

            $oldValues = ['status' => $eventParticipant->status->value];
            $eventParticipant->registration_number = $this->registrationNumberGenerator->next(
                $lockedEvent,
                $lockedParticipant->gender,
            );
            $eventParticipant->registration_number_scope = EventParticipant::registrationNumberScopeFor(
                $lockedParticipant->gender,
            );
            $eventParticipant->active_registration_number = $eventParticipant->registration_number;
            $eventParticipant->status = ParticipantStatus::Waiting;
            $eventParticipant->current_service_post_id = $initialRoute->servicePost->id;
            $eventParticipant->checked_in_at = now();
            $eventParticipant->registration_order = $this->nextRegistrationOrder($lockedEvent);
            $eventParticipant->checked_in_by = $actor?->id;
            $eventParticipant->save();

            EventParticipantStatusHistory::query()->create([
                'event_id' => $lockedEvent->id,
                'event_participant_id' => $eventParticipant->id,
                'from_status' => $oldValues['status'],
                'to_status' => ParticipantStatus::Waiting,
                'changed_by' => $actor?->id,
            ]);

            $this->syncSelectedServices($eventParticipant, $serviceTypes);
            $queueTicket = $this->createInitialQueueTicket(
                $lockedEvent,
                $eventParticipant,
                $initialRoute->servicePost,
            );
            $queueTicket->load(['event.settings', 'eventParticipant.participant', 'eventParticipant.services', 'servicePost']);
            $eventParticipant->load(['participant', 'event', 'currentServicePost', 'checkedInBy', 'services']);

            $this->auditLogger->record(
                actor: $actor,
                subject: $eventParticipant,
                action: AuditAction::ParticipantCheckedIn,
                eventId: $lockedEvent->id,
                oldValues: $oldValues,
                newValues: [
                    'status' => $eventParticipant->status->value,
                    'registration_number' => $eventParticipant->formattedRegistrationNumber($settings),
                    'services' => array_map(
                        fn (ParticipantServiceType $serviceType): string => $serviceType->value,
                        $serviceTypes,
                    ),
                    'current_service_post_id' => $initialRoute->servicePost->id,
                    'queue_ticket_id' => $queueTicket->id,
                    'queue_number' => $queueTicket->formattedNumber(),
                ],
            );

            return new CheckInResult(
                $eventParticipant,
                $queueTicket,
                $eventParticipant->formattedRegistrationNumber($settings) ?? $queueTicket->formattedNumber(),
                false,
            );
        });

        if (! $result->alreadyCheckedIn) {
            $this->realtimePublisher->participantRegistered(
                $result->eventParticipant,
                $result->queueTicket,
                $result->queueTicket->servicePost->behavior,
            );
            ParticipantCheckedIn::dispatch(
                eventId: $result->eventParticipant->event_id,
                eventParticipantId: $result->eventParticipant->id,
                queueTicketId: $result->queueTicket->id,
                queueNumber: $result->queueTicket->formattedNumber(),
                registrationNumber: $result->registrationNumber,
                services: array_map(
                    fn (ParticipantServiceType $serviceType): string => $serviceType->value,
                    $serviceTypes,
                ),
            );
        } elseif ($result->recoveredMissingTicket) {
            $this->realtimePublisher->queueChanged(
                $result->queueTicket,
                AuditAction::ParticipantCheckedIn,
            );
        }

        return $result;
    }

    public function cancel(Event $event, EventParticipant $eventParticipant, ?User $actor): EventParticipant
    {
        /** @var EventParticipant $cancelled */
        $cancelled = $this->database->transaction(function () use (
            $event,
            $eventParticipant,
            $actor,
        ): EventParticipant {
            $lockedEvent = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $this->ensureActiveEvent($lockedEvent);
            $participant = EventParticipant::query()
                ->where('event_id', $lockedEvent->id)
                ->whereKey($eventParticipant->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($participant->status === ParticipantStatus::Cancelled) {
                return $participant;
            }

            $tickets = QueueTicket::query()
                ->where('event_participant_id', $participant->id)
                ->lockForUpdate()
                ->get();

            if ($participant->status !== ParticipantStatus::Waiting
                || $tickets->isEmpty()
                || $tickets->contains(
                    fn (QueueTicket $ticket): bool => $ticket->status !== QueueTicketStatus::Waiting,
                )) {
                throw ValidationException::withMessages([
                    'registration' => 'Registrasi hanya dapat dibatalkan sebelum peserta masuk proses operasional.',
                ]);
            }

            if ($tickets->contains(
                fn (QueueTicket $ticket): bool => in_array($ticket->status, [
                    QueueTicketStatus::Serving,
                    QueueTicketStatus::Finished,
                ], true),
            )) {
                throw ValidationException::withMessages([
                    'registration' => 'Registrasi tidak dapat dibatalkan karena pelayanan sudah dimulai atau diselesaikan.',
                ]);
            }

            $participantServices = $participant->services()
                ->lockForUpdate()
                ->get();

            if ($participantServices->contains(
                fn ($service): bool => in_array($service->status, [
                    ParticipantServiceStatus::ScreeningInProgress,
                    ParticipantServiceStatus::DonationInProgress,
                    ParticipantServiceStatus::HealthCheckInProgress,
                    ParticipantServiceStatus::Completed,
                    ParticipantServiceStatus::NotEligible,
                ], true),
            )) {
                throw ValidationException::withMessages([
                    'registration' => 'Registrasi tidak dapat dibatalkan karena hasil pelayanan harus tetap menjadi histori.',
                ]);
            }

            $activeTickets = $tickets->whereNotIn('status', [
                QueueTicketStatus::Cancelled,
            ]);

            foreach ($activeTickets as $ticket) {
                $this->queueStateMachine->transition($ticket, QueueTicketStatus::Cancelled);
                $ticket->active_number = null;
                $ticket->cancelled_at = now();
                $ticket->cancelled_by = $actor?->id;
                $ticket->save();
            }

            foreach ($participantServices as $participantService) {
                $participantService->status = ParticipantServiceStatus::Cancelled;
                $participantService->completed_at = now();
                $participantService->save();
            }
            $oldStatus = $participant->status;
            $participant->status = ParticipantStatus::Cancelled;
            $participant->active_registration_number = null;
            $participant->current_service_post_id = null;
            $participant->cancelled_at = now();
            $participant->cancelled_by = $actor?->id;
            $participant->completed_at = now();
            $participant->save();

            $this->auditLogger->record(
                actor: $actor,
                subject: $participant,
                action: AuditAction::ParticipantRegistrationCancelled,
                eventId: $lockedEvent->id,
                oldValues: ['status' => $oldStatus->value],
                newValues: [
                    'status' => ParticipantStatus::Cancelled->value,
                    'released_registration_number' => $participant->registration_number,
                    'cancelled_queue_ticket_ids' => $activeTickets->modelKeys(),
                ],
            );

            return $participant;
        });

        $cancelledTicket = $cancelled->queueTickets()
            ->where('status', QueueTicketStatus::Cancelled->value)
            ->latest('cancelled_at')
            ->first();

        if ($cancelledTicket !== null) {
            $this->realtimePublisher->queueChanged(
                $cancelledTicket,
                AuditAction::ParticipantRegistrationCancelled,
            );
        }

        return $cancelled;
    }

    /**
     * @param  list<ParticipantServiceType|string>  $services
     */
    public function updateServices(
        Event $event,
        EventParticipant $eventParticipant,
        ?User $actor,
        array $services,
    ): QueueTicket {
        $serviceTypes = $this->normalizeServiceTypes($services);

        /** @var QueueTicket $updatedTicket */
        $updatedTicket = $this->database->transaction(function () use (
            $event,
            $eventParticipant,
            $actor,
            $serviceTypes,
        ): QueueTicket {
            $lockedEvent = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $this->ensureActiveEvent($lockedEvent);
            $participant = EventParticipant::query()
                ->where('event_id', $lockedEvent->id)
                ->whereKey($eventParticipant->id)
                ->lockForUpdate()
                ->firstOrFail();
            $tickets = QueueTicket::query()
                ->where('event_participant_id', $participant->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($participant->status !== ParticipantStatus::Waiting
                || $tickets->count() !== 1
                || $tickets->first()?->status !== QueueTicketStatus::Waiting) {
                throw ValidationException::withMessages([
                    'registration' => 'Layanan hanya dapat diubah sebelum peserta masuk proses operasional.',
                ]);
            }

            $this->participantServiceWorkflow->registrationRoute($lockedEvent, $serviceTypes, true);
            $oldServices = $this->selectedServices($participant);
            $this->syncSelectedServices($participant, $serviceTypes);

            $this->auditLogger->record(
                actor: $actor,
                subject: $participant,
                action: AuditAction::ParticipantServicesUpdated,
                eventId: $lockedEvent->id,
                oldValues: [
                    'services' => array_map(
                        fn (ParticipantServiceType $serviceType): string => $serviceType->value,
                        $oldServices,
                    ),
                ],
                newValues: [
                    'services' => array_map(
                        fn (ParticipantServiceType $serviceType): string => $serviceType->value,
                        $serviceTypes,
                    ),
                ],
            );

            $ticket = $tickets->firstOrFail();
            $ticket->load([
                'event.settings',
                'eventParticipant.participant',
                'eventParticipant.services',
                'servicePost',
            ]);

            return $ticket;
        });

        $this->realtimePublisher->queueChanged(
            $updatedTicket,
            AuditAction::ParticipantServicesUpdated,
        );

        return $updatedTicket;
    }

    private function ensureActiveEvent(Event $event): void
    {
        if ($event->status !== EventStatus::Active || $event->active_marker !== 'active') {
            throw ValidationException::withMessages([
                'event' => 'Check-in hanya dapat dilakukan pada event yang sedang aktif.',
            ]);
        }
    }

    private function existingQueueTicket(EventParticipant $eventParticipant): ?QueueTicket
    {
        return QueueTicket::query()
            ->where('event_participant_id', $eventParticipant->id)
            ->with(['event.settings', 'eventParticipant.participant', 'eventParticipant.services', 'servicePost'])
            ->oldest('id')
            ->first();
    }

    /**
     * @return list<ParticipantServiceType>
     */
    private function selectedServices(EventParticipant $eventParticipant): array
    {
        return array_values(
            $eventParticipant->services()
                ->lockForUpdate()
                ->get()
                ->map(fn ($service): ParticipantServiceType => $service->service)
                ->all(),
        );
    }

    /**
     * @param  list<ParticipantServiceType>  $serviceTypes
     */
    private function syncSelectedServices(EventParticipant $eventParticipant, array $serviceTypes): void
    {
        $selectedValues = array_map(
            fn (ParticipantServiceType $service): string => $service->value,
            $serviceTypes,
        );
        $existingServices = $eventParticipant->services()
            ->lockForUpdate()
            ->get()
            ->keyBy(fn ($service): string => $service->service->value);

        $eventParticipant->services()
            ->whereNotIn('service', $selectedValues)
            ->delete();

        foreach ($serviceTypes as $serviceType) {
            if ($existingServices->has($serviceType->value)) {
                continue;
            }

            $eventParticipant->services()->create([
                'event_id' => $eventParticipant->event_id,
                'service' => $serviceType,
                'status' => $serviceType->initialStatus($serviceTypes),
                'selected_at' => now(),
            ]);
        }
    }

    private function createInitialQueueTicket(
        Event $event,
        EventParticipant $eventParticipant,
        ServicePost $servicePost,
    ): QueueTicket {
        return QueueTicket::query()->create([
            'event_id' => $event->id,
            'event_participant_id' => $eventParticipant->id,
            'service_post_id' => $servicePost->id,
            'queue_type' => QueueType::General,
            'number' => $this->queueNumberGenerator->next($event, $servicePost),
            'status' => QueueTicketStatus::Waiting,
        ]);
    }

    private function nextRegistrationOrder(Event $event): int
    {
        return (int) EventParticipant::query()
            ->where('event_id', $event->id)
            ->lockForUpdate()
            ->max('registration_order') + 1;
    }

    /**
     * @param  list<ParticipantServiceType|string>  $services
     * @return list<ParticipantServiceType>
     */
    private function normalizeServiceTypes(array $services): array
    {
        $serviceTypes = collect($services)
            ->map(fn (ParticipantServiceType|string $service): ParticipantServiceType => $service instanceof ParticipantServiceType
                ? $service
                : ParticipantServiceType::from($service))
            ->unique(fn (ParticipantServiceType $service): string => $service->value)
            ->values();

        if ($serviceTypes->isEmpty()) {
            throw ValidationException::withMessages([
                'services' => 'Pilih minimal satu layanan untuk peserta.',
            ]);
        }

        return array_values($serviceTypes->all());
    }
}
