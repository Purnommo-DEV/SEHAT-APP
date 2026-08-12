<?php

namespace App\Services\Workflow;

use App\Enums\ParticipantStatus;
use App\Models\EventParticipant;
use Illuminate\Validation\ValidationException;

class ParticipantStateMachine
{
    public function transition(EventParticipant $eventParticipant, ParticipantStatus $nextStatus): void
    {
        $currentStatus = $eventParticipant->status;

        if (! in_array($nextStatus, $this->allowedTransitions($currentStatus), true)) {
            throw ValidationException::withMessages([
                'status' => "Transisi status dari {$currentStatus->label()} ke {$nextStatus->label()} tidak diperbolehkan.",
            ]);
        }

        $eventParticipant->status = $nextStatus;
    }

    /**
     * @return list<ParticipantStatus>
     */
    private function allowedTransitions(ParticipantStatus $status): array
    {
        return match ($status) {
            ParticipantStatus::Waiting => [ParticipantStatus::HealthCheck, ParticipantStatus::Cancelled],
            ParticipantStatus::HealthCheck => [ParticipantStatus::Donating, ParticipantStatus::Finished, ParticipantStatus::Cancelled],
            ParticipantStatus::Donating => [ParticipantStatus::Finished, ParticipantStatus::Cancelled],
            ParticipantStatus::Registered => [ParticipantStatus::CheckedIn, ParticipantStatus::WaitingService, ParticipantStatus::Cancelled],
            ParticipantStatus::CheckedIn => [ParticipantStatus::WaitingService, ParticipantStatus::WaitingHealth, ParticipantStatus::Cancelled],
            ParticipantStatus::WaitingService => [ParticipantStatus::ServiceInProgress, ParticipantStatus::Finished, ParticipantStatus::NotEligible, ParticipantStatus::Cancelled],
            ParticipantStatus::ServiceInProgress => [ParticipantStatus::WaitingService, ParticipantStatus::Finished, ParticipantStatus::NotEligible, ParticipantStatus::Cancelled],
            ParticipantStatus::WaitingHealth => [ParticipantStatus::HealthInProgress, ParticipantStatus::Cancelled],
            ParticipantStatus::HealthInProgress => [
                ParticipantStatus::WaitingScreening,
                ParticipantStatus::HealthCheckCompleted,
                ParticipantStatus::Finished,
                ParticipantStatus::Cancelled,
            ],
            ParticipantStatus::WaitingScreening => [ParticipantStatus::NotEligible, ParticipantStatus::WaitingDonor, ParticipantStatus::Finished, ParticipantStatus::Cancelled],
            ParticipantStatus::NotEligible => [ParticipantStatus::Finished],
            ParticipantStatus::WaitingDonor => [ParticipantStatus::DonationInProgress, ParticipantStatus::Cancelled],
            ParticipantStatus::DonationInProgress => [ParticipantStatus::DonationCompleted, ParticipantStatus::Cancelled],
            ParticipantStatus::DonationCompleted => [ParticipantStatus::WaitingHealth, ParticipantStatus::Finished],
            ParticipantStatus::HealthCheckCompleted => [ParticipantStatus::Finished],
            ParticipantStatus::Finished, ParticipantStatus::Cancelled => [],
        };
    }
}
