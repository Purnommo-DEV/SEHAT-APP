<?php

namespace App\Services\Workflow;

use App\Data\BehaviorCompletionResult;
use App\Data\ParticipantServiceRegistrationRoute;
use App\Data\ParticipantServiceTransition;
use App\Enums\ParticipantServiceStatus;
use App\Enums\ParticipantServiceType;
use App\Enums\ParticipantStatus;
use App\Enums\ScreeningResult;
use App\Enums\ServicePostBehavior;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventParticipantService;
use App\Models\ServicePost;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Routes operational work from explicitly selected participant services.
 *
 * No post name, code, or legacy type participates in routing. The only
 * configuration contract is an active ServicePost behavior for each step.
 */
class ParticipantServiceWorkflowService
{
    public function __construct(private readonly WorkflowDefinitionService $workflow) {}

    /**
     * @param  list<ParticipantServiceType>  $services
     */
    public function registrationRoute(Event $event, array $services, bool $lock = false): ParticipantServiceRegistrationRoute
    {
        $this->workflow->ensureServicesAvailable($event, $services, $lock);

        $firstService = collect($services)
            ->sortBy(fn (ParticipantServiceType $service): int => $service->registrationPriority())
            ->first();

        if (! $firstService instanceof ParticipantServiceType) {
            throw ValidationException::withMessages([
                'services' => 'Pilih minimal satu layanan untuk peserta.',
            ]);
        }

        return new ParticipantServiceRegistrationRoute(
            $this->requiredPost($event, $firstService->entryBehavior(), $lock),
            $firstService->initialParticipantStatus(),
        );
    }

    public function start(EventParticipant $participant, ServicePost $servicePost): ?ParticipantStatus
    {
        $services = $this->lockedServices($participant);

        if ($services->isEmpty()) {
            return null;
        }

        return match ($servicePost->behavior) {
            ServicePostBehavior::ScreeningForm => $this->startDonorScreening($services),
            ServicePostBehavior::DonationForm => $this->startDonation($services),
            ServicePostBehavior::HealthForm => $this->startHealthCheck($services),
            default => null,
        };
    }

    public function complete(
        Event $event,
        EventParticipant $participant,
        ServicePost $servicePost,
        BehaviorCompletionResult $completion,
    ): ?ParticipantServiceTransition {
        $services = $this->lockedServices($participant);

        if ($services->isEmpty()) {
            return null;
        }

        return match ($servicePost->behavior) {
            ServicePostBehavior::ScreeningForm => $this->completeScreening($event, $services, $completion),
            ServicePostBehavior::DonationForm => $this->completeDonation($event, $services),
            ServicePostBehavior::HealthForm => $this->completeHealthCheck($services),
            default => null,
        };
    }

    /**
     * @return Collection<int, EventParticipantService>
     */
    private function lockedServices(EventParticipant $participant): Collection
    {
        return EventParticipantService::query()
            ->where('event_participant_id', $participant->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  Collection<int, EventParticipantService>  $services
     */
    private function startDonorScreening(Collection $services): ParticipantStatus
    {
        $donor = $this->requiredService($services, ParticipantServiceType::Donor);
        $this->ensureStatus($donor, [
            ParticipantServiceStatus::WaitingScreening,
            ParticipantServiceStatus::ScreeningInProgress,
        ]);
        $donor->status = ParticipantServiceStatus::ScreeningInProgress;
        $donor->eligibility_started_at ??= now();
        $donor->save();

        return ParticipantStatus::ServiceInProgress;
    }

    /**
     * @param  Collection<int, EventParticipantService>  $services
     */
    private function startDonation(Collection $services): ParticipantStatus
    {
        $donor = $this->requiredService($services, ParticipantServiceType::Donor);
        $this->ensureStatus($donor, [
            ParticipantServiceStatus::WaitingDonation,
            ParticipantServiceStatus::DonationInProgress,
        ]);
        $donor->status = ParticipantServiceStatus::DonationInProgress;
        $donor->started_at ??= now();
        $donor->save();

        return ParticipantStatus::DonationInProgress;
    }

    /**
     * @param  Collection<int, EventParticipantService>  $services
     */
    private function startHealthCheck(Collection $services): ParticipantStatus
    {
        $healthCheck = $this->requiredService($services, ParticipantServiceType::HealthCheck);
        $this->ensureStatus($healthCheck, [
            ParticipantServiceStatus::WaitingHealthCheck,
            ParticipantServiceStatus::HealthCheckInProgress,
        ]);
        $healthCheck->status = ParticipantServiceStatus::HealthCheckInProgress;
        $healthCheck->started_at ??= now();
        $healthCheck->save();

        return ParticipantStatus::HealthInProgress;
    }

    /**
     * @param  Collection<int, EventParticipantService>  $services
     */
    private function completeScreening(
        Event $event,
        Collection $services,
        BehaviorCompletionResult $completion,
    ): ParticipantServiceTransition {
        $donor = $this->requiredService($services, ParticipantServiceType::Donor);
        $this->ensureStatus($donor, [
            ParticipantServiceStatus::WaitingScreening,
            ParticipantServiceStatus::ScreeningInProgress,
        ]);

        if ($completion->screeningResult === null) {
            throw ValidationException::withMessages([
                'result' => 'Hasil kelayakan donor wajib tersedia.',
            ]);
        }

        $donor->eligibility_started_at ??= now();
        $donor->eligibility_completed_at = now();

        if ($completion->screeningResult === ScreeningResult::Eligible) {
            $donor->status = ParticipantServiceStatus::WaitingDonation;
            $donor->save();

            return new ParticipantServiceTransition(
                $this->requiredPost($event, ServicePostBehavior::DonationForm, true),
                ParticipantStatus::WaitingDonor,
            );
        }

        $donor->status = ParticipantServiceStatus::NotEligible;
        $donor->completed_at = now();
        $donor->save();

        $healthCheck = $this->findService($services, ParticipantServiceType::HealthCheck);

        if ($healthCheck === null) {
            return new ParticipantServiceTransition(null, ParticipantStatus::NotEligible);
        }

        if ($healthCheck->status === ParticipantServiceStatus::Completed) {
            return new ParticipantServiceTransition(null, ParticipantStatus::HealthCheckCompleted);
        }

        $this->ensureStatus($healthCheck, [ParticipantServiceStatus::Pending]);
        $healthCheck->status = ParticipantServiceStatus::WaitingHealthCheck;
        $healthCheck->save();

        return new ParticipantServiceTransition(
            $this->requiredPost($event, ServicePostBehavior::HealthForm, true),
            ParticipantStatus::WaitingHealth,
        );
    }

    /**
     * @param  Collection<int, EventParticipantService>  $services
     */
    private function completeDonation(Event $event, Collection $services): ParticipantServiceTransition
    {
        $donor = $this->requiredService($services, ParticipantServiceType::Donor);
        $this->ensureStatus($donor, [
            ParticipantServiceStatus::WaitingDonation,
            ParticipantServiceStatus::DonationInProgress,
        ]);
        $donor->started_at ??= now();
        $donor->status = ParticipantServiceStatus::Completed;
        $donor->completed_at = now();
        $donor->save();

        $healthCheck = $this->findService($services, ParticipantServiceType::HealthCheck);

        if ($healthCheck === null) {
            return new ParticipantServiceTransition(null, ParticipantStatus::DonationCompleted);
        }

        if ($healthCheck->status === ParticipantServiceStatus::Completed) {
            return new ParticipantServiceTransition(null, ParticipantStatus::HealthCheckCompleted);
        }

        if ($healthCheck->status === ParticipantServiceStatus::Cancelled) {
            return new ParticipantServiceTransition(null, ParticipantStatus::DonationCompleted);
        }

        $this->ensureStatus($healthCheck, [ParticipantServiceStatus::Pending]);
        $healthCheck->status = ParticipantServiceStatus::WaitingHealthCheck;
        $healthCheck->save();

        return new ParticipantServiceTransition(
            $this->requiredPost($event, ServicePostBehavior::HealthForm, true),
            ParticipantStatus::WaitingHealth,
        );
    }

    /**
     * @param  Collection<int, EventParticipantService>  $services
     */
    private function completeHealthCheck(Collection $services): ParticipantServiceTransition
    {
        $healthCheck = $this->requiredService($services, ParticipantServiceType::HealthCheck);
        $this->ensureStatus($healthCheck, [
            ParticipantServiceStatus::WaitingHealthCheck,
            ParticipantServiceStatus::HealthCheckInProgress,
        ]);
        $healthCheck->started_at ??= now();
        $healthCheck->status = ParticipantServiceStatus::Completed;
        $healthCheck->completed_at = now();
        $healthCheck->save();

        return new ParticipantServiceTransition(null, ParticipantStatus::HealthCheckCompleted);
    }

    public function cancel(
        Event $event,
        EventParticipant $participant,
        ServicePost $servicePost,
    ): ?ParticipantServiceTransition {
        $services = $this->lockedServices($participant);

        if ($services->isEmpty()) {
            return null;
        }

        if (in_array($servicePost->behavior, [
            ServicePostBehavior::ScreeningForm,
            ServicePostBehavior::DonationForm,
        ], true)) {
            $donor = $this->requiredService($services, ParticipantServiceType::Donor);
            $donor->status = ParticipantServiceStatus::Cancelled;
            $donor->completed_at = now();
            $donor->save();

            $healthCheck = $this->findService($services, ParticipantServiceType::HealthCheck);

            if ($healthCheck !== null && $healthCheck->status === ParticipantServiceStatus::Pending) {
                $healthCheck->status = ParticipantServiceStatus::WaitingHealthCheck;
                $healthCheck->save();

                return new ParticipantServiceTransition(
                    $this->requiredPost($event, ServicePostBehavior::HealthForm, true),
                    ParticipantStatus::WaitingHealth,
                );
            }

            return new ParticipantServiceTransition(null, ParticipantStatus::Cancelled);
        }

        if ($servicePost->behavior === ServicePostBehavior::HealthForm) {
            $healthCheck = $this->requiredService($services, ParticipantServiceType::HealthCheck);
            $healthCheck->status = ParticipantServiceStatus::Cancelled;
            $healthCheck->completed_at = now();
            $healthCheck->save();

            return new ParticipantServiceTransition(null, ParticipantStatus::Cancelled);
        }

        return null;
    }

    /**
     * @param  Collection<int, EventParticipantService>  $services
     */
    private function requiredService(Collection $services, ParticipantServiceType $type): EventParticipantService
    {
        $service = $this->findService($services, $type);

        if ($service === null) {
            throw ValidationException::withMessages([
                'service' => "Peserta tidak memilih layanan {$type->label()}.",
            ]);
        }

        return $service;
    }

    /**
     * @param  Collection<int, EventParticipantService>  $services
     */
    private function findService(Collection $services, ParticipantServiceType $type): ?EventParticipantService
    {
        $service = $services->first(
            fn (EventParticipantService $selected): bool => $selected->service === $type,
        );

        return $service instanceof EventParticipantService ? $service : null;
    }

    /**
     * @param  list<ParticipantServiceStatus>  $allowedStatuses
     */
    private function ensureStatus(EventParticipantService $service, array $allowedStatuses): void
    {
        if (! in_array($service->status, $allowedStatuses, true)) {
            throw ValidationException::withMessages([
                'service' => "Status {$service->service->label()} tidak dapat diproses dari {$service->status->label()}.",
            ]);
        }
    }

    private function requiredPost(Event $event, ServicePostBehavior $behavior, bool $lock): ServicePost
    {
        $servicePost = $this->workflow->firstActivePostForBehavior($event, $behavior, $lock);

        if ($servicePost === null) {
            throw ValidationException::withMessages([
                'event' => "Pos aktif untuk {$behavior->label()} belum dikonfigurasi.",
            ]);
        }

        return $servicePost;
    }
}
