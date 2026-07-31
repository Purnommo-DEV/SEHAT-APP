<?php

namespace App\Services\Workflow;

use App\Data\WorkflowValidationResult;
use App\Enums\ParticipantServiceType;
use App\Enums\ServicePostBehavior;
use App\Models\Event;
use App\Models\ServicePost;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class WorkflowDefinitionService
{
    public function validate(Event $event): WorkflowValidationResult
    {
        $activePosts = $this->activePosts($event);
        $configuredBehaviors = $this->configuredBehaviors($activePosts);
        $availableServices = [];
        $errors = [];

        foreach (ParticipantServiceType::cases() as $service) {
            $missingBehaviors = $this->missingBehaviors($service, $configuredBehaviors);
            $availableServices[$service->value] = $missingBehaviors === [];

            if ($missingBehaviors !== []) {
                $missingLabels = collect($missingBehaviors)
                    ->map(fn (ServicePostBehavior $behavior): string => $behavior->label())
                    ->join(', ');
                $errors[] = "Layanan {$service->label()} belum tersedia: tambahkan pos aktif {$missingLabels}.";
            }
        }

        return new WorkflowValidationResult(
            valid: $errors === [],
            errors: $errors,
            firstPost: $this->firstAvailablePost($activePosts, $availableServices),
            availableServices: $availableServices,
        );
    }

    /**
     * @param  list<ParticipantServiceType>  $services
     */
    public function ensureServicesAvailable(
        Event $event,
        array $services,
        bool $lock = false,
    ): void {
        $configuredBehaviors = $this->configuredBehaviors($this->activePosts($event, $lock));
        $errors = [];

        foreach ($services as $service) {
            $missingBehaviors = $this->missingBehaviors($service, $configuredBehaviors);

            if ($missingBehaviors === []) {
                continue;
            }

            $missingLabels = collect($missingBehaviors)
                ->map(fn (ServicePostBehavior $behavior): string => $behavior->label())
                ->join(', ');
            $errors[] = "Layanan {$service->label()} belum siap. Pos aktif yang diperlukan: {$missingLabels}.";
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['services' => $errors]);
        }
    }

    public function firstActivePost(Event $event, bool $lock = false): ?ServicePost
    {
        return ServicePost::query()
            ->where('event_id', $event->id)
            ->where('is_active', true)
            ->orderBy('sequence')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    public function nextActivePost(Event $event, ServicePost $currentPost, bool $lock = false): ?ServicePost
    {
        return ServicePost::query()
            ->where('event_id', $event->id)
            ->where('is_active', true)
            ->where('sequence', '>', $currentPost->sequence)
            ->orderBy('sequence')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    public function firstActivePostForBehavior(
        Event $event,
        ServicePostBehavior $behavior,
        bool $lock = false,
    ): ?ServicePost {
        return ServicePost::query()
            ->where('event_id', $event->id)
            ->where('behavior', $behavior->value)
            ->where('is_active', true)
            ->orderBy('sequence')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    /**
     * @return Collection<int, ServicePost>
     */
    private function activePosts(Event $event, bool $lock = false): Collection
    {
        return ServicePost::query()
            ->where('event_id', $event->id)
            ->where('is_active', true)
            ->orderBy('sequence')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get();
    }

    /**
     * @param  Collection<int, ServicePost>  $activePosts
     * @return list<ServicePostBehavior>
     */
    private function configuredBehaviors(Collection $activePosts): array
    {
        return array_values($activePosts
            ->map(fn (ServicePost $servicePost): ServicePostBehavior => $servicePost->behavior)
            ->unique(fn (ServicePostBehavior $behavior): string => $behavior->value)
            ->all());
    }

    /**
     * @param  list<ServicePostBehavior>  $configuredBehaviors
     * @return list<ServicePostBehavior>
     */
    private function missingBehaviors(
        ParticipantServiceType $service,
        array $configuredBehaviors,
    ): array {
        return array_values(array_filter(
            $service->requiredBehaviors(),
            fn (ServicePostBehavior $behavior): bool => ! in_array($behavior, $configuredBehaviors, true),
        ));
    }

    /**
     * @param  Collection<int, ServicePost>  $activePosts
     * @param  array<string, bool>  $availableServices
     */
    private function firstAvailablePost(
        Collection $activePosts,
        array $availableServices,
    ): ?ServicePost {
        $firstService = collect(ParticipantServiceType::cases())
            ->sortBy(fn (ParticipantServiceType $service): int => $service->registrationPriority())
            ->first(fn (ParticipantServiceType $service): bool => $availableServices[$service->value] ?? false);

        if (! $firstService instanceof ParticipantServiceType) {
            return null;
        }

        $firstPost = $activePosts->first(
            fn (ServicePost $post): bool => $post->behavior === $firstService->entryBehavior(),
        );

        return $firstPost instanceof ServicePost ? $firstPost : null;
    }
}
