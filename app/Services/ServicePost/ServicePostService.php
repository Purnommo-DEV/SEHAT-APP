<?php

namespace App\Services\ServicePost;

use App\Enums\AuditAction;
use App\Enums\EventStatus;
use App\Enums\ParticipantServiceStatus;
use App\Enums\ParticipantServiceType;
use App\Enums\QueueTicketStatus;
use App\Enums\ServicePostBehavior;
use App\Enums\ServicePostMoveDirection;
use App\Enums\ServicePostType;
use App\Events\ServicePostUpdated;
use App\Models\Event;
use App\Models\EventParticipantService;
use App\Models\ServicePost;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;

class ServicePostService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(Event $event, array $attributes, User $actor): ServicePost
    {
        /** @var ServicePost $servicePost */
        $servicePost = $this->database->transaction(function () use ($event, $attributes, $actor): ServicePost {
            $lockedEvent = $this->lockEvent($event);
            $behavior = $attributes['behavior'] instanceof ServicePostBehavior
                ? $attributes['behavior']
                : ServicePostBehavior::from((string) $attributes['behavior']);
            $isActiveRepair = $lockedEvent->status === EventStatus::Active
                && in_array($behavior, $this->creatableBehaviors($lockedEvent), true);
            $this->ensureCanCreate($lockedEvent, $isActiveRepair);
            $operatorIds = $attributes['operator_ids'] ?? [];
            unset($attributes['operator_ids']);

            $servicePost = new ServicePost($attributes);
            $servicePost->event_id = $lockedEvent->id;
            $servicePost->type = $this->legacyTypeFor($attributes['type'] ?? null);
            $servicePost->sequence = $this->nextSequence($lockedEvent);
            $servicePost->is_active = $isActiveRepair || ($attributes['is_active'] ?? true);
            $servicePost->save();
            $servicePost->operators()->sync($operatorIds);

            $this->writeAudit($servicePost, $actor, AuditAction::ServicePostCreated);

            return $servicePost;
        });

        $this->broadcast($servicePost, AuditAction::ServicePostCreated);

        return $servicePost;
    }

    public function canCreate(Event $event): bool
    {
        return $this->creatableBehaviors($event) !== [];
    }

    public function canBootstrapWorkflow(Event $event): bool
    {
        return $this->isWorkflowBootstrapAllowed($event);
    }

    /**
     * @return list<ServicePostBehavior>
     */
    public function creatableBehaviors(Event $event): array
    {
        if ($event->status === EventStatus::Draft) {
            return ServicePostBehavior::cases();
        }

        if ($event->status !== EventStatus::Active) {
            return [];
        }

        $configuredBehaviors = ServicePost::query()
            ->where('event_id', $event->id)
            ->pluck('behavior')
            ->map(fn (ServicePostBehavior|string $behavior): ?ServicePostBehavior => $behavior instanceof ServicePostBehavior
                ? $behavior
                : ServicePostBehavior::tryFrom($behavior))
            ->filter()
            ->all();

        return array_values(array_filter(
            [
                ServicePostBehavior::ScreeningForm,
                ServicePostBehavior::DonationForm,
                ServicePostBehavior::HealthForm,
            ],
            fn (ServicePostBehavior $behavior): bool => ! in_array($behavior, $configuredBehaviors, true),
        ));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Event $event, ServicePost $servicePost, array $attributes, User $actor): ServicePost
    {
        /** @var ServicePost $updatedServicePost */
        $updatedServicePost = $this->database->transaction(function () use ($event, $servicePost, $attributes, $actor): ServicePost {
            $lockedEvent = $this->lockEvent($event);
            $this->ensureDraft($lockedEvent, 'Data pos hanya dapat diubah saat event masih berstatus draf.');
            $lockedServicePost = $this->lockServicePost($lockedEvent, $servicePost);
            $oldValues = $this->snapshot($lockedServicePost);
            $operatorIds = $attributes['operator_ids'] ?? [];
            unset($attributes['operator_ids']);
            if (array_key_exists('type', $attributes)) {
                $lockedServicePost->type = ServicePostType::tryFrom((string) $attributes['type'])
                    ?? $lockedServicePost->type;
            }
            $lockedServicePost->fill($attributes);
            $lockedServicePost->save();
            $lockedServicePost->operators()->sync($operatorIds);

            $this->writeAudit($lockedServicePost, $actor, AuditAction::ServicePostUpdated, $oldValues);

            return $lockedServicePost;
        });

        $this->broadcast($updatedServicePost, AuditAction::ServicePostUpdated);

        return $updatedServicePost;
    }

    public function move(
        Event $event,
        ServicePost $servicePost,
        ServicePostMoveDirection $direction,
        User $actor,
    ): ServicePost {
        /** @var ServicePost $movedServicePost */
        $movedServicePost = $this->database->transaction(function () use ($event, $servicePost, $direction, $actor): ServicePost {
            $lockedEvent = $this->lockEvent($event);
            $this->ensureDraft($lockedEvent, 'Urutan pos hanya dapat diubah saat event masih berstatus draf.');
            $lockedServicePost = $this->lockServicePost($lockedEvent, $servicePost);

            $targetQuery = ServicePost::query()
                ->where('event_id', $lockedEvent->id)
                ->where(
                    'sequence',
                    $direction === ServicePostMoveDirection::Up ? '<' : '>',
                    $lockedServicePost->sequence,
                )
                ->orderBy('sequence', $direction === ServicePostMoveDirection::Up ? 'desc' : 'asc')
                ->lockForUpdate();

            $targetServicePost = $targetQuery->first();

            if ($targetServicePost === null) {
                throw ValidationException::withMessages([
                    'direction' => $direction === ServicePostMoveDirection::Up
                        ? 'Pos ini sudah berada pada urutan pertama.'
                        : 'Pos ini sudah berada pada urutan terakhir.',
                ]);
            }

            $oldSourceValues = $this->snapshot($lockedServicePost);
            $oldTargetValues = $this->snapshot($targetServicePost);
            $sourceSequence = $lockedServicePost->sequence;
            $targetSequence = $targetServicePost->sequence;

            $lockedServicePost->sequence = $this->nextSequence($lockedEvent);
            $lockedServicePost->save();
            $targetServicePost->sequence = $sourceSequence;
            $targetServicePost->save();
            $lockedServicePost->sequence = $targetSequence;
            $lockedServicePost->save();

            $this->writeAudit($lockedServicePost, $actor, AuditAction::ServicePostReordered, $oldSourceValues);
            $this->writeAudit($targetServicePost, $actor, AuditAction::ServicePostReordered, $oldTargetValues);

            return $lockedServicePost;
        });

        $this->broadcast($movedServicePost, AuditAction::ServicePostReordered);

        return $movedServicePost;
    }

    public function toggle(Event $event, ServicePost $servicePost, User $actor): ServicePost
    {
        /** @var ServicePost $updatedServicePost */
        $updatedServicePost = $this->database->transaction(function () use ($event, $servicePost, $actor): ServicePost {
            $lockedEvent = $this->lockEvent($event);

            if (! in_array($lockedEvent->status, [EventStatus::Draft, EventStatus::Active], true)) {
                throw ValidationException::withMessages([
                    'service_post' => 'Pos hanya dapat diaktifkan atau dinonaktifkan pada event draf maupun aktif.',
                ]);
            }

            $lockedServicePost = $this->lockServicePost($lockedEvent, $servicePost);

            if ($lockedEvent->status === EventStatus::Active && $lockedServicePost->is_active) {
                $hasParticipants = $lockedServicePost->currentParticipants()
                    ->lockForUpdate()
                    ->exists();
                $hasOpenTickets = $lockedServicePost->queueTickets()
                    ->whereNotIn('status', [
                        QueueTicketStatus::Finished->value,
                        QueueTicketStatus::Cancelled->value,
                    ])
                    ->lockForUpdate()
                    ->exists();

                if ($hasParticipants || $hasOpenTickets) {
                    throw ValidationException::withMessages([
                        'service_post' => 'Pos masih memiliki peserta. Selesaikan atau pindahkan seluruh antrean sebelum menonaktifkan pos.',
                    ]);
                }

                $this->ensureNoPendingServiceDependency($lockedEvent, $lockedServicePost);
            }

            $oldValues = $this->snapshot($lockedServicePost);
            $lockedServicePost->is_active = ! $lockedServicePost->is_active;
            $lockedServicePost->save();

            $this->writeAudit(
                $lockedServicePost,
                $actor,
                $lockedServicePost->is_active ? AuditAction::ServicePostActivated : AuditAction::ServicePostDeactivated,
                $oldValues,
            );

            return $lockedServicePost;
        });

        $this->broadcast(
            $updatedServicePost,
            $updatedServicePost->is_active ? AuditAction::ServicePostActivated : AuditAction::ServicePostDeactivated,
        );

        return $updatedServicePost;
    }

    public function delete(Event $event, ServicePost $servicePost, User $actor): void
    {
        $servicePostId = $servicePost->getKey();

        $this->database->transaction(function () use ($event, $servicePost, $actor): void {
            $lockedEvent = $this->lockEvent($event);
            $this->ensureDraft($lockedEvent, 'Pos hanya dapat dihapus saat event masih berstatus draf.');
            $lockedServicePost = $this->lockServicePost($lockedEvent, $servicePost);

            if ($lockedServicePost->queueTickets()->exists()
                || $lockedServicePost->submissions()->exists()
                || $lockedServicePost->currentParticipants()->exists()
            ) {
                throw ValidationException::withMessages([
                    'service_post' => 'Pos yang sudah memiliki riwayat pelayanan tidak dapat dihapus.',
                ]);
            }

            $this->writeAudit($lockedServicePost, $actor, AuditAction::ServicePostDeleted);
            $lockedServicePost->delete();
            $this->normalizeSequences($lockedEvent);
        });

        ServicePostUpdated::dispatch($event->id, $servicePostId, AuditAction::ServicePostDeleted);
    }

    private function lockEvent(Event $event): Event
    {
        return Event::query()
            ->whereKey($event->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockServicePost(Event $event, ServicePost $servicePost): ServicePost
    {
        return ServicePost::query()
            ->where('event_id', $event->id)
            ->whereKey($servicePost->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function ensureDraft(Event $event, string $message): void
    {
        if ($event->status !== EventStatus::Draft) {
            throw ValidationException::withMessages(['event' => $message]);
        }
    }

    private function ensureCanCreate(Event $event, bool $isActiveRepair): void
    {
        if ($event->status === EventStatus::Draft || $isActiveRepair) {
            return;
        }

        throw ValidationException::withMessages([
            'event' => $event->status === EventStatus::Active
                ? 'Pada event aktif, pos baru hanya dapat ditambahkan untuk layanan SOP yang belum memiliki pos.'
                : 'Pos baru hanya dapat dibuat saat event masih berstatus draf.',
        ]);
    }

    private function isWorkflowBootstrapAllowed(Event $event): bool
    {
        if ($event->status !== EventStatus::Active) {
            return false;
        }

        return ! ServicePost::query()
            ->where('event_id', $event->id)
            ->lockForUpdate()
            ->exists();
    }

    private function ensureNoPendingServiceDependency(Event $event, ServicePost $servicePost): void
    {
        $dependentService = match ($servicePost->behavior) {
            ServicePostBehavior::ScreeningForm,
            ServicePostBehavior::DonationForm => ParticipantServiceType::Donor,
            ServicePostBehavior::HealthForm => ParticipantServiceType::HealthCheck,
            default => null,
        };

        if ($dependentService === null) {
            return;
        }

        $hasAlternativePost = ServicePost::query()
            ->where('event_id', $event->id)
            ->where('behavior', $servicePost->behavior->value)
            ->where('is_active', true)
            ->whereKeyNot($servicePost->id)
            ->lockForUpdate()
            ->exists();

        if ($hasAlternativePost) {
            return;
        }

        $terminalStatuses = collect(ParticipantServiceStatus::cases())
            ->filter(fn (ParticipantServiceStatus $status): bool => $status->isTerminal())
            ->map(fn (ParticipantServiceStatus $status): string => $status->value)
            ->all();
        $hasPendingDependency = EventParticipantService::query()
            ->where('event_id', $event->id)
            ->where('service', $dependentService->value)
            ->whereNotIn('status', $terminalStatuses)
            ->lockForUpdate()
            ->exists();

        if ($hasPendingDependency) {
            throw ValidationException::withMessages([
                'service_post' => "Pos terakhir untuk {$dependentService->label()} tidak dapat dinonaktifkan karena masih ada peserta yang belum menyelesaikan layanan.",
            ]);
        }
    }

    private function nextSequence(Event $event): int
    {
        return ((int) ServicePost::query()
            ->where('event_id', $event->id)
            ->lockForUpdate()
            ->max('sequence')) + 1;
    }

    private function normalizeSequences(Event $event): void
    {
        $posts = ServicePost::query()
            ->where('event_id', $event->id)
            ->orderBy('sequence')
            ->lockForUpdate()
            ->get();

        foreach ($posts as $post) {
            $post->sequence = 1_000_000 + $post->sequence;
            $post->save();
        }

        foreach ($posts->values() as $index => $post) {
            $post->sequence = $index + 1;
            $post->save();
        }
    }

    private function legacyTypeFor(mixed $type): ServicePostType
    {
        $explicitType = $type instanceof ServicePostType
            ? $type
            : ServicePostType::tryFrom((string) $type);

        if ($explicitType !== null) {
            return $explicitType;
        }

        // New workflow posts are routed by `behavior`, never by this
        // compatibility column. Keep the legacy type neutral unless an old
        // client explicitly supplied it.
        return ServicePostType::Custom;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(ServicePost $servicePost): array
    {
        return [
            'code' => $servicePost->code,
            'name' => $servicePost->name,
            'description' => $servicePost->description,
            'type' => $servicePost->type->value,
            'behavior' => $servicePost->behavior->value,
            'queue_prefix' => $servicePost->queue_prefix,
            'queue_number_digits' => $servicePost->queue_number_digits,
            'sequence' => $servicePost->sequence,
            'is_active' => $servicePost->is_active,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     */
    private function writeAudit(
        ServicePost $servicePost,
        User $actor,
        AuditAction $action,
        ?array $oldValues = null,
    ): void {
        $this->auditLogger->record(
            actor: $actor,
            subject: $servicePost,
            action: $action,
            eventId: $servicePost->event_id,
            oldValues: $oldValues,
            newValues: $this->snapshot($servicePost),
        );
    }

    private function broadcast(ServicePost $servicePost, AuditAction $action): void
    {
        ServicePostUpdated::dispatch($servicePost->event_id, $servicePost->id, $action);
    }
}
