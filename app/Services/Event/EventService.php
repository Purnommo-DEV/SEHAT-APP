<?php

namespace App\Services\Event;

use App\Enums\AuditAction;
use App\Enums\EventStatus;
use App\Enums\ParticipantGender;
use App\Enums\ParticipantServiceStatus;
use App\Enums\QueueTicketStatus;
use App\Events\EventLifecycleUpdated;
use App\Models\Event;
use App\Models\EventParticipantService;
use App\Models\EventSetting;
use App\Models\QueueTicket;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Workflow\WorkflowDefinitionService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class EventService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly AuditLogger $auditLogger,
        private readonly WorkflowDefinitionService $workflow,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, User $actor): Event
    {
        /** @var Event $event */
        $event = $this->database->transaction(function () use ($attributes, $actor): Event {
            $event = new Event($attributes);
            $event->created_by = $actor->id;
            $event->status = EventStatus::Draft;
            $event->save();

            $event->settings()->create();
            $event->donationCapacityLanes()->createMany([
                ['gender' => ParticipantGender::Male],
                ['gender' => ParticipantGender::Female],
            ]);
            $event->load('settings');

            $this->writeAudit($event, $actor, AuditAction::EventCreated);

            return $event;
        });

        $this->broadcast($event, AuditAction::EventCreated);

        return $event;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Event $event, array $attributes, User $actor): Event
    {
        /** @var Event $updatedEvent */
        $updatedEvent = $this->database->transaction(function () use ($event, $attributes, $actor): Event {
            $lockedEvent = $this->lockEvent($event);
            $this->ensureDraft($lockedEvent, 'Data event hanya dapat diubah selama masih berstatus draf.');

            $oldValues = $this->snapshot($lockedEvent);
            $lockedEvent->fill($attributes);
            $lockedEvent->save();
            $lockedEvent->load('settings');

            $this->writeAudit($lockedEvent, $actor, AuditAction::EventUpdated, $oldValues);

            return $lockedEvent;
        });

        $this->broadcast($updatedEvent, AuditAction::EventUpdated);

        return $updatedEvent;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateSettings(Event $event, array $attributes, User $actor): EventSetting
    {
        /** @var EventSetting $settings */
        $settings = $this->database->transaction(function () use ($event, $attributes, $actor): EventSetting {
            $lockedEvent = $this->lockEvent($event);
            $this->ensureDraft($lockedEvent, 'Pengaturan event hanya dapat diubah selama event masih berstatus draf.');

            $settings = $lockedEvent->settings()->lockForUpdate()->firstOrFail();
            $oldValues = $settings->only([
                'registration_number_format',
                'registration_queue_prefix',
                'registration_male_prefix',
                'registration_female_prefix',
                'registration_queue_digits',
                'donor_number_mode',
                'donor_queue_prefix',
                'donor_queue_digits',
                'donation_capacity_male',
                'donation_capacity_female',
                'general_queue_digits',
                'male_donor_queue_prefix',
                'female_donor_queue_prefix',
            ]);
            $settings->fill($attributes);
            $settings->save();

            $lockedEvent->load('settings');
            $this->writeAudit($lockedEvent, $actor, AuditAction::EventSettingsUpdated, $oldValues, $settings->only([
                'registration_number_format',
                'registration_queue_prefix',
                'registration_male_prefix',
                'registration_female_prefix',
                'registration_queue_digits',
                'donor_number_mode',
                'donor_queue_prefix',
                'donor_queue_digits',
                'donation_capacity_male',
                'donation_capacity_female',
                'general_queue_digits',
                'male_donor_queue_prefix',
                'female_donor_queue_prefix',
            ]));

            return $settings;
        });

        $this->broadcast($event, AuditAction::EventSettingsUpdated);

        return $settings;
    }

    public function activate(Event $event, User $actor): Event
    {
        try {
            /** @var Event $activatedEvent */
            $activatedEvent = $this->database->transaction(function () use ($event, $actor): Event {
                $lockedEvent = $this->lockEvent($event);
                $this->ensureDraft($lockedEvent, 'Hanya event berstatus draf yang dapat diaktifkan.');

                $activeEvent = Event::query()
                    ->where('active_marker', 'active')
                    ->lockForUpdate()
                    ->first();

                if ($activeEvent !== null) {
                    throw ValidationException::withMessages([
                        'event' => "Event aktif {$activeEvent->name} harus diselesaikan atau dibatalkan terlebih dahulu.",
                    ]);
                }

                $workflowValidation = $this->workflow->validate($lockedEvent);

                if (! $workflowValidation->valid) {
                    throw ValidationException::withMessages([
                        'event' => $workflowValidation->errors,
                    ]);
                }

                $oldValues = $this->snapshot($lockedEvent);
                $lockedEvent->status = EventStatus::Active;
                $lockedEvent->active_marker = 'active';
                $lockedEvent->save();
                $lockedEvent->load('settings');

                $this->writeAudit($lockedEvent, $actor, AuditAction::EventActivated, $oldValues);

                return $lockedEvent;
            });
        } catch (QueryException $exception) {
            if (str_contains($exception->getMessage(), 'active_marker')) {
                throw ValidationException::withMessages([
                    'event' => 'Event aktif baru saja dipilih oleh panitia lain. Muat ulang data event.',
                ]);
            }

            throw $exception;
        }

        $this->broadcast($activatedEvent, AuditAction::EventActivated);

        return $activatedEvent;
    }

    public function complete(Event $event, User $actor): Event
    {
        return $this->finish($event, $actor, EventStatus::Completed, AuditAction::EventCompleted);
    }

    public function cancel(Event $event, User $actor): Event
    {
        /** @var Event $cancelledEvent */
        $cancelledEvent = $this->database->transaction(function () use ($event, $actor): Event {
            $lockedEvent = $this->lockEvent($event);

            if (! in_array($lockedEvent->status, [EventStatus::Draft, EventStatus::Active], true)) {
                throw ValidationException::withMessages([
                    'event' => 'Hanya event draf atau aktif yang dapat dibatalkan.',
                ]);
            }

            $this->ensureNoOpenOperations($lockedEvent);

            $oldValues = $this->snapshot($lockedEvent);
            $lockedEvent->status = EventStatus::Cancelled;
            $lockedEvent->active_marker = null;
            $lockedEvent->ended_at = now();
            $lockedEvent->save();
            $lockedEvent->load('settings');

            $this->writeAudit($lockedEvent, $actor, AuditAction::EventCancelled, $oldValues);

            return $lockedEvent;
        });

        $this->broadcast($cancelledEvent, AuditAction::EventCancelled);

        return $cancelledEvent;
    }

    public function delete(Event $event, User $actor): void
    {
        $eventId = $event->getKey();

        $this->database->transaction(function () use ($event, $actor): void {
            $lockedEvent = $this->lockEvent($event);
            $this->ensureDraft($lockedEvent, 'Hanya event draf yang dapat dihapus.');

            $this->writeAudit($lockedEvent, $actor, AuditAction::EventDeleted);
            $lockedEvent->delete();
        });

        $this->broadcastById($eventId, AuditAction::EventDeleted);
    }

    private function finish(Event $event, User $actor, EventStatus $status, AuditAction $action): Event
    {
        /** @var Event $finishedEvent */
        $finishedEvent = $this->database->transaction(function () use ($event, $actor, $status, $action): Event {
            $lockedEvent = $this->lockEvent($event);

            if ($lockedEvent->status !== EventStatus::Active) {
                throw ValidationException::withMessages([
                    'event' => 'Hanya event aktif yang dapat diselesaikan.',
                ]);
            }

            $this->ensureNoOpenOperations($lockedEvent);

            $oldValues = $this->snapshot($lockedEvent);
            $lockedEvent->status = $status;
            $lockedEvent->active_marker = null;
            $lockedEvent->ended_at = now();
            $lockedEvent->save();
            $lockedEvent->load('settings');

            $this->writeAudit($lockedEvent, $actor, $action, $oldValues);

            return $lockedEvent;
        });

        $this->broadcast($finishedEvent, $action);

        return $finishedEvent;
    }

    private function lockEvent(Event $event): Event
    {
        return Event::query()
            ->whereKey($event->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function ensureDraft(Event $event, string $message): void
    {
        if ($event->status !== EventStatus::Draft) {
            throw ValidationException::withMessages(['event' => $message]);
        }
    }

    private function ensureNoOpenOperations(Event $event): void
    {
        $openTicket = QueueTicket::query()
            ->where('event_id', $event->id)
            ->whereNotIn('status', [
                QueueTicketStatus::Finished->value,
                QueueTicketStatus::Cancelled->value,
            ])
            ->lockForUpdate()
            ->first(['id']);

        $terminalServiceStatuses = collect(ParticipantServiceStatus::cases())
            ->filter(fn (ParticipantServiceStatus $status): bool => $status->isTerminal())
            ->map(fn (ParticipantServiceStatus $status): string => $status->value)
            ->all();
        $openParticipantService = EventParticipantService::query()
            ->where('event_id', $event->id)
            ->whereNotIn('status', $terminalServiceStatuses)
            ->lockForUpdate()
            ->first(['id']);

        if ($openTicket !== null || $openParticipantService !== null) {
            throw ValidationException::withMessages([
                'event' => 'Event masih memiliki antrean atau layanan peserta yang belum selesai. Selesaikan atau batalkan seluruh proses peserta terlebih dahulu.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    private function writeAudit(
        Event $event,
        User $actor,
        AuditAction $action,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): void {
        $this->auditLogger->record(
            actor: $actor,
            subject: $event,
            action: $action,
            eventId: $event->id,
            oldValues: $oldValues,
            newValues: $newValues ?? $this->snapshot($event),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Event $event): array
    {
        return [
            'code' => $event->code,
            'name' => $event->name,
            'description' => $event->description,
            'location' => $event->location,
            'starts_at' => $event->starts_at->toAtomString(),
            'ends_at' => $event->ends_at?->toAtomString(),
            'status' => $event->status->value,
            'active' => $event->active_marker === 'active',
            'ended_at' => $event->ended_at?->toAtomString(),
        ];
    }

    private function broadcast(Event $event, AuditAction $action): void
    {
        $this->broadcastById($event->getKey(), $action);
    }

    private function broadcastById(int $eventId, AuditAction $action): void
    {
        EventLifecycleUpdated::dispatch($eventId, $action);
    }
}
