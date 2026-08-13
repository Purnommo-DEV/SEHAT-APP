<?php

namespace App\Http\Resources;

use App\Enums\ParticipantServiceStatus;
use App\Enums\ParticipantServiceType;
use App\Enums\ParticipantStatus;
use App\Models\EventParticipant;
use App\Models\EventParticipantService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EventParticipant
 */
class OperationalParticipantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $donorService = $this->services
            ->first(fn (EventParticipantService $service): bool => $service->service === ParticipantServiceType::Donor);
        $healthService = $this->services
            ->first(fn (EventParticipantService $service): bool => $service->service === ParticipantServiceType::HealthCheck);
        $hasDonorService = $donorService instanceof EventParticipantService;
        $hasHealthService = $healthService instanceof EventParticipantService;
        $eligibilityResult = match ($donorService?->status) {
            ParticipantServiceStatus::WaitingHealthCheck => 'eligible',
            ParticipantServiceStatus::NotEligible => 'not_eligible',
            default => null,
        };
        $canContinueToHealthCheck = $this->status === ParticipantStatus::WaitingScreening
            && $hasHealthService
            && $eligibilityResult !== null;

        return [
            'id' => $this->id,
            'number' => $this->formattedRegistrationNumber($this->event->settings) ?? '-',
            'registration_number' => $this->formattedRegistrationNumber($this->event->settings),
            'registration_order' => $this->registration_order,
            'sort_number' => $this->registration_order,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'position' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'call' => [
                'is_active' => in_array($this->status, [
                    ParticipantStatus::Calling,
                    ParticipantStatus::HealthCheck,
                    ParticipantStatus::WaitingScreening,
                    ParticipantStatus::Donating,
                ], true),
                'status_label' => $this->status === ParticipantStatus::Calling
                    ? 'Sedang Dipanggil'
                    : 'Sedang Diproses',
                'target_label' => $this->calledTargetLabel($hasDonorService, $eligibilityResult),
            ],
            'eligibility' => [
                'result' => $eligibilityResult,
                'label' => match ($eligibilityResult) {
                    'eligible' => 'Layak Donor',
                    'not_eligible' => 'Tidak Layak Donor',
                    default => null,
                },
            ],
            'completed_at' => $this->completed_at?->toIso8601String(),
            'participant' => [
                'id' => $this->participant->id,
                'name' => $this->participant->name,
                'gender' => $this->participant->gender->value,
                'gender_label' => $this->participant->gender->label(),
            ],
            'services' => $this->services
                ->map(fn (EventParticipantService $service): array => [
                    'value' => $service->service->value,
                    'label' => $service->service->label(),
                ])
                ->values()
                ->all(),
            'can_start_health_check' => ($this->status === ParticipantStatus::Calling && $hasHealthService && ! $hasDonorService)
                || $canContinueToHealthCheck,
            'can_continue_to_health_check' => $canContinueToHealthCheck,
            'can_start_eligibility' => $hasDonorService && $this->status === ParticipantStatus::Calling,
            'can_decide_eligibility' => $this->status === ParticipantStatus::WaitingScreening
                && $donorService?->status === ParticipantServiceStatus::WaitingScreening,
            'can_donate' => $this->status === ParticipantStatus::HealthCheck
                && $donorService?->status === ParticipantServiceStatus::WaitingHealthCheck,
            'can_complete_before_donation' => $this->status === ParticipantStatus::HealthCheck
                && (! $hasDonorService || $eligibilityResult === 'not_eligible'),
            'can_complete_health_only' => $this->status === ParticipantStatus::HealthCheck && ! $hasDonorService,
            'urls' => [
                'health_check' => route('events.operations.health-check.start', [$this->event_id, $this->resource]),
                'eligibility' => route('events.operations.eligibility.start', [$this->event_id, $this->resource]),
                'eligible' => route('events.operations.eligibility.eligible', [$this->event_id, $this->resource]),
                'ineligible' => route('events.operations.eligibility.ineligible', [$this->event_id, $this->resource]),
                'donate' => route('events.operations.donating.start', [$this->event_id, $this->resource]),
                'complete' => route('events.operations.completed.store', [$this->event_id, $this->resource]),
                'complete_before_donation' => route('events.operations.health-check.complete', [$this->event_id, $this->resource]),
                'complete_health_only' => route('events.operations.health-check.complete', [$this->event_id, $this->resource]),
            ],
        ];
    }

    private function calledTargetLabel(bool $hasDonorService, ?string $eligibilityResult): string
    {
        return match ($this->status) {
            ParticipantStatus::Calling => $hasDonorService ? 'Cek Kelayakan Donor' : 'Cek Kesehatan',
            ParticipantStatus::HealthCheck => $hasDonorService && $eligibilityResult === 'eligible'
                ? 'Donor'
                : 'Selesai',
            ParticipantStatus::WaitingScreening => $eligibilityResult === 'not_eligible'
                ? 'Lanjut ke Cek Kesehatan'
                : 'Cek Kelayakan Donor',
            ParticipantStatus::Donating => 'Donor',
            ParticipantStatus::Finished => 'Selesai',
            default => $hasDonorService ? 'Cek Kelayakan Donor' : 'Cek Kesehatan',
        };
    }
}
